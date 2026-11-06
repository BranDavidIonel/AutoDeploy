<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Services\Deploy\DeployScripts;
use App\Services\Remote\ConnectionFactory;
use App\Services\Remote\Shell;
use Throwable;

/**
 * "Quick setup" of a project on its server: checks what is missing and
 * creates the folder structure, imports .env and storage from an existing
 * copy of the site, and trusts the git host's SSH key.
 */
class ProjectSetup
{
    public function __construct(private readonly ConnectionFactory $connections) {}

    /**
     * @return array{ok: bool, error: string|null, checks: array<string, string>}
     */
    public function check(Project $project): array
    {
        $connection = null;

        try {
            $connection = $this->connections->make($project->server);
            $scripts = new DeployScripts($project, 'check');
            $result = $connection->run($scripts->wrap($this->checkScript($project)), null, 90);

            return ['ok' => true, 'error' => null, 'checks' => $result->keyValues()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'checks' => []];
        } finally {
            $connection?->close();
        }
    }

    /**
     * @return array{ok: bool, output: string}
     */
    public function run(Project $project, ?string $importFrom = null): array
    {
        $connection = null;

        try {
            $connection = $this->connections->make($project->server);
            $scripts = new DeployScripts($project, 'setup');
            $result = $connection->run($scripts->wrap($this->setupScript($project, $importFrom)), null, 600);

            return ['ok' => $result->successful(), 'output' => mb_scrub($result->output, 'UTF-8')];
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        } finally {
            $connection?->close();
        }
    }

    private function checkScript(Project $project): string
    {
        $repo = Shell::quote($project->repository);
        $branch = Shell::quote($project->branch);
        $public = Shell::quote($project->currentPath().'/public');

        return implode("\n", [
            'set +e',
            'yes_no() { if "$@" >/dev/null 2>&1; then echo yes; else echo no; fi; }',
            'echo "deploy_path=$(yes_no test -d "$DEPLOY_PATH")"',
            'echo "structure=$(yes_no test -d "$DEPLOY_PATH/releases" -a -d "$SHARED_PATH")"',
            'echo "env=$(yes_no test -s "$SHARED_PATH/.env")"',
            'echo "app_key=$(if grep -q "^APP_KEY=..*" "$SHARED_PATH/.env" 2>/dev/null; then echo yes; else echo no; fi)"',
            'echo "storage=$(yes_no test -d "$SHARED_PATH/storage")"',
            'if [ -L "$CURRENT_PATH" ]; then echo "current=$(basename "$(readlink "$CURRENT_PATH")")"; elif [ -e "$CURRENT_PATH" ]; then echo "current=not_a_link"; else echo "current=none"; fi',
            'echo "git=$(yes_no command -v git)"',
            'echo "composer=$(yes_no command -v '.Shell::quote(strtok($project->composer_binary, ' ') ?: 'composer').')"',
            'echo "php=$($PHP -r \'echo PHP_VERSION;\' 2>/dev/null || echo no)"',
            'echo "node=$(node -v 2>/dev/null || echo no)"',
            'echo "supervisor=$(yes_no command -v supervisorctl)"',
            'echo "web_user=$(if [ -z "$WEB_USER" ]; then echo none; elif id "$WEB_USER" >/dev/null 2>&1; then echo yes; else echo no; fi)"',
            // Which web server answers (Apache or Nginx) and whether its site serves current/public.
            'WEB=none',
            'if pgrep -x apache2 >/dev/null 2>&1 || pgrep -x httpd >/dev/null 2>&1; then WEB=apache; elif pgrep -x nginx >/dev/null 2>&1; then WEB=nginx; fi',
            'echo "web_server=$WEB"',
            'case "$WEB" in',
            '  apache) if grep -Rqsi -- "DocumentRoot[[:space:]]*\"\\?"'.$public.' /etc/apache2/sites-enabled /etc/httpd/conf.d; then echo web_root=yes; else echo web_root=no; fi ;;',
            '  nginx) if grep -Rqs -- '.$public.' /etc/nginx/sites-enabled /etc/nginx/conf.d; then echo web_root=yes; else echo web_root=no; fi ;;',
            '  *) echo web_root=missing ;;',
            'esac',
            "if timeout 25 git ls-remote --exit-code --heads {$repo} {$branch} >/dev/null 2>&1; then echo repo=yes; else echo \"repo=no\"; fi",
            'echo "ssh_key=$(if [ -f ~/.ssh/id_ed25519.pub ] || [ -f ~/.ssh/id_rsa.pub ]; then echo yes; else echo no; fi)"',
        ]);
    }

    private function setupScript(Project $project, ?string $importFrom): string
    {
        $lines = [
            'HAD_STORAGE=no; if [ -d "$SHARED_PATH/storage" ]; then HAD_STORAGE=yes; fi',
            'mkdir -p "$DEPLOY_PATH/releases" "$SHARED_PATH" "$DEPLOY_PATH/.autodeploy"',
            'echo "✔ Folders: $DEPLOY_PATH/{releases,shared}"',
        ];

        $sharesStorage = in_array('storage', array_map(fn ($d) => trim($d, '/'), $project->sharedDirs()), true);

        if ($importFrom) {
            $from = Shell::quote(rtrim($importFrom, '/'));
            $lines[] = "FROM={$from}";
            $lines[] = 'if [ ! -d "$FROM" ]; then echo "✖ $FROM does not exist on the server"; exit 1; fi';
            $lines[] = 'if [ -f "$FROM/.env" ] && [ ! -s "$SHARED_PATH/.env" ]; then cp "$FROM/.env" "$SHARED_PATH/.env"; echo "✔ Copied $FROM/.env to shared/.env"; elif [ -s "$SHARED_PATH/.env" ]; then echo "• shared/.env already exists, left as it is"; else echo "• $FROM has no .env"; fi';

            if ($sharesStorage) {
                $lines[] = 'if [ "$HAD_STORAGE" = "no" ] && [ -d "$FROM/storage" ]; then cp -a "$FROM/storage" "$SHARED_PATH/storage"; echo "✔ Copied $FROM/storage to shared/storage ($(du -sh "$SHARED_PATH/storage" | cut -f1))"; elif [ "$HAD_STORAGE" = "yes" ]; then echo "• shared/storage already exists, left as it is"; fi';
            }
        }

        if ($sharesStorage) {
            $lines[] = 'mkdir -p "$SHARED_PATH/storage/app/public" "$SHARED_PATH/storage/framework/cache/data" "$SHARED_PATH/storage/framework/sessions" "$SHARED_PATH/storage/framework/views" "$SHARED_PATH/storage/logs"';
            $lines[] = 'echo "✔ Laravel storage folders"';
        }

        if (in_array('.env', $project->sharedFiles(), true)) {
            $lines[] = 'if [ ! -s "$SHARED_PATH/.env" ]; then echo "! shared/.env is still missing: paste it in the Environment tab"; fi';
        }

        if ($host = $project->repositoryHost()) {
            $q = Shell::quote($host);
            $lines[] = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh';
            $lines[] = "if ssh-keygen -F {$q} >/dev/null 2>&1; then echo \"• {$host} is already a known host\"; else ssh-keyscan -t ed25519,rsa {$q} >> ~/.ssh/known_hosts 2>/dev/null && echo \"✔ Added {$host} to known_hosts\" || echo \"! Could not reach {$host}\"; fi";
        }

        $lines[] = 'if [ -z "$(ls -A ~/.ssh/*.pub 2>/dev/null)" ]; then echo "! The server has no SSH key yet: create one on the server page and add it to GitHub as a deploy key"; fi';
        $lines[] = 'echo "Repository check:"';
        $lines[] = 'if timeout 25 git ls-remote --exit-code --heads '.Shell::quote($project->repository).' '.Shell::quote($project->branch).' >/dev/null; then echo "✔ The server can read '.$project->branch.' from the repository"; else echo "✖ The server cannot read the repository (add the server SSH key as a deploy key)"; fi';
        $lines[] = $this->permissionsLine();

        return implode("\n", $lines);
    }

    private function permissionsLine(): string
    {
        return 'if [ -n "$WEB_USER" ] && id "$WEB_USER" >/dev/null 2>&1 && [ "$(id -u)" = "0" ] && [ -d "$SHARED_PATH/storage" ]; then chown -R "$WEB_USER":"$WEB_USER" "$SHARED_PATH/storage/" && echo "✔ shared/storage belongs to $WEB_USER"; fi';
    }
}
