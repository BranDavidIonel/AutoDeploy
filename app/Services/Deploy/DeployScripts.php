<?php

namespace App\Services\Deploy;

use App\Models\Deployment;
use App\Models\Project;
use App\Services\Remote\Shell;

/**
 * The bash scripts of every deploy step. Each one runs on the server with the
 * variables from variables() exported (also available in custom hooks):
 *
 *   $DEPLOY_PATH   /var/www/app
 *   $RELEASE       20261106183000
 *   $RELEASE_PATH  /var/www/app/releases/20261106183000
 *   $SHARED_PATH   /var/www/app/shared
 *   $CURRENT_PATH  /var/www/app/current   (symlink to the live release)
 *   $PHP, $COMPOSER_BIN, $BRANCH, $COMMIT, $WEB_USER, $PROJECT
 *
 * Layout on the server (like Envoyer / Deployer):
 *   releases/<release>/   one folder per deploy, the last N are kept
 *   shared/.env, shared/storage/   kept between releases, symlinked into each release
 *   current -> releases/<release>   what Nginx serves (root …/current/public)
 */
class DeployScripts
{
    public const string COMMIT_MARKER = '__AUTODEPLOY_COMMIT__';

    public const string RELEASES_MARKER = '__AUTODEPLOY_RELEASES__';

    public function __construct(
        private readonly Project $project,
        private readonly string $release,
    ) {}

    /** @return array<string, string> */
    public function variables(?Deployment $deployment = null): array
    {
        $project = $this->project;

        return [
            'PROJECT' => $project->slug,
            'DEPLOY_PATH' => $project->deployPath(),
            'RELEASE' => $this->release,
            'RELEASE_PATH' => $project->releasePath($this->release),
            'SHARED_PATH' => $project->sharedPath(),
            'CURRENT_PATH' => $project->currentPath(),
            'PHP' => $project->php_binary,
            'COMPOSER_BIN' => $project->composer_binary,
            'BRANCH' => (string) ($deployment->branch ?? $project->branch),
            'COMMIT' => (string) ($deployment->commit_hash ?? ''),
            'WEB_USER' => (string) $project->web_user,
        ];
    }

    /** Adds the common preamble: stderr into stdout, stop on errors, PATH, variables. */
    public function wrap(string $body, ?Deployment $deployment = null): string
    {
        return implode("\n", [
            'exec 2>&1',
            // Node installed with nvm is not on the PATH of non-interactive shells.
            'if [ -s "$HOME/.nvm/nvm.sh" ]; then . "$HOME/.nvm/nvm.sh" >/dev/null 2>&1 || true; fi',
            'export PATH="$HOME/.config/composer/vendor/bin:$HOME/.composer/vendor/bin:/usr/local/bin:$PATH"',
            'export COMPOSER_ALLOW_SUPERUSER=1 GIT_TERMINAL_PROMPT=0',
            'export GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new -o BatchMode=yes"',
            Shell::exports($this->variables($deployment)),
            'set -e',
            $body,
        ]);
    }

    public function prepare(): string
    {
        $lines = [
            'mkdir -p "$DEPLOY_PATH/releases" "$SHARED_PATH" "$DEPLOY_PATH/.autodeploy"',
            'if ! command -v git >/dev/null 2>&1; then',
            '  echo "git is not installed on the server (apt install git)."',
            '  exit 1',
            'fi',
            'if [ -e "$CURRENT_PATH" ] && [ ! -L "$CURRENT_PATH" ]; then',
            '  echo "$CURRENT_PATH exists and is a normal folder, not a link."',
            '  echo "Move it away (it is probably an old copy of the site) and deploy again."',
            '  exit 1',
            'fi',
        ];

        if (in_array('.env', $this->project->sharedFiles(), true)) {
            $lines[] = 'if [ ! -f "$SHARED_PATH/.env" ]; then';
            $lines[] = '  echo "Missing $SHARED_PATH/.env"';
            $lines[] = '  echo "Open the Environment tab in AutoDeploy (or Quick setup to import it), save the .env, then deploy again."';
            $lines[] = '  exit 1';
            $lines[] = 'fi';
        }

        return implode("\n", [
            ...$lines,
            'echo "Connected as $(whoami) on $(hostname)"',
            'echo "New release: $RELEASE"',
            'if command -v free >/dev/null 2>&1; then',
            '  free -m | awk \'/^Mem:/ {print "Memory: " $7 " MB available of " $2 " MB"}\'',
            'fi',
            'df -h "$DEPLOY_PATH" 2>/dev/null | awk \'NR==2 {print "Disk: " $4 " free of " $2}\' || true',
        ]);
    }

    /** Fetches only one commit (or the tip of the branch) into the new release folder. */
    public function clone(string $ref): string
    {
        $repo = Shell::quote($this->project->repository);
        $quotedRef = Shell::quote($ref);
        $isCommit = (bool) preg_match('/^[0-9a-f]{7,40}$/i', $ref);

        return implode("\n", [
            'rm -rf "$RELEASE_PATH"',
            'mkdir -p "$RELEASE_PATH"',
            'cd "$RELEASE_PATH"',
            'git -c init.defaultBranch=main init -q',
            "git remote add origin {$repo}",
            "echo \"Fetching {$ref} from {$this->project->repository}\"",
            "if ! git fetch --depth 1 --no-tags origin {$quotedRef}; then",
            '  echo ""',
            $isCommit
                ? '  echo "Could not fetch this commit. Make sure it is pushed to the repository, and that the server can read the repository (deploy key)."'
                : '  echo "Could not fetch the branch. Check the branch name and that the server can read the repository (deploy key)."',
            '  exit 1',
            'fi',
            'git -c advice.detachedHead=false checkout -q FETCH_HEAD',
            'echo "'.self::COMMIT_MARKER.'	$(git log -1 --format=\'%H%x09%an%x09%s\')"',
            'git log -1 --format=\'Commit %h by %an: %s\'',
        ]);
    }

    /** Moves shared folders / files out of the release and links them from shared/. */
    public function shared(): string
    {
        $lines = ['cd "$RELEASE_PATH"'];

        foreach ($this->project->sharedDirs() as $dir) {
            $q = Shell::quote(trim($dir, '/'));
            $lines[] = "S=\"\$SHARED_PATH\"/{$q}; R=\"\$RELEASE_PATH\"/{$q}";
            $lines[] = 'if [ ! -e "$S" ]; then';
            $lines[] = '  mkdir -p "$(dirname "$S")"';
            $lines[] = "  if [ -d \"\$R\" ]; then cp -a \"\$R\" \"\$S\"; echo \"Created shared/{$dir} (copied from the release)\"; else mkdir -p \"\$S\"; echo \"Created shared/{$dir}\"; fi";
            $lines[] = 'fi';
            $lines[] = 'rm -rf "$R"';
            $lines[] = 'mkdir -p "$(dirname "$R")"';
            $lines[] = 'ln -s "$S" "$R"';
            $lines[] = "echo \"Linked {$dir}\"";
        }

        if (in_array('storage', array_map(fn ($d) => trim($d, '/'), $this->project->sharedDirs()), true)) {
            // Laravel needs these folders; an empty storage/ from git may not have them.
            $lines[] = 'mkdir -p "$SHARED_PATH/storage/app/public" "$SHARED_PATH/storage/framework/cache/data" "$SHARED_PATH/storage/framework/sessions" "$SHARED_PATH/storage/framework/views" "$SHARED_PATH/storage/logs"';
        }

        foreach ($this->project->sharedFiles() as $file) {
            $q = Shell::quote(trim($file, '/'));
            $lines[] = "S=\"\$SHARED_PATH\"/{$q}; R=\"\$RELEASE_PATH\"/{$q}";
            $lines[] = 'if [ ! -e "$S" ]; then';
            $lines[] = '  mkdir -p "$(dirname "$S")"';
            $lines[] = '  touch "$S"';
            $lines[] = "  echo \"Created an empty shared/{$file}\"";
            $lines[] = 'fi';
            $lines[] = 'rm -f "$R"';
            $lines[] = 'mkdir -p "$(dirname "$R")"';
            $lines[] = 'ln -s "$S" "$R"';
            $lines[] = "echo \"Linked {$file}\"";
        }

        return implode("\n", $lines);
    }

    public function composer(): string
    {
        $project = $this->project;

        return implode("\n", [
            'cd "$RELEASE_PATH"',
            'if [ ! -f composer.json ]; then',
            '  echo "No composer.json in the project, nothing to install."',
            '  exit 0',
            'fi',
            'echo "$ '.$project->composer_binary.' install '.$project->composer_flags.'"',
            $project->composer_binary.' install '.$project->composer_flags,
        ]);
    }

    /** A custom command, run inside the release folder. Each command is printed (dimmed) right before it runs. */
    public function hook(string $script): string
    {
        return implode("\n", [
            'cd "$RELEASE_PATH"',
            'trap \'printf "\033[2m$ %s\033[0m\n" "$BASH_COMMAND"\' DEBUG',
            str_replace("\r\n", "\n", $script),
        ]);
    }

    public function serverBuild(): string
    {
        $project = $this->project;
        $output = Shell::quote($project->build_output);

        return implode("\n", [
            'cd "$RELEASE_PATH"',
            'if command -v free >/dev/null 2>&1; then',
            '  AVAILABLE=$(free -m | awk \'/^Mem:/ {print $7}\')',
            '  echo "Memory available: ${AVAILABLE} MB"',
            '  if [ "${AVAILABLE:-0}" -lt 1024 ]; then',
            '    echo "WARNING: less than 1 GB of RAM is free. If the build gets killed, switch the project to \"Build locally\" or \"Upload a saved build\"."',
            '  fi',
            'fi',
            'echo '.Shell::quote('$ '.$project->server_build_command),
            $project->server_build_command,
            "if [ ! -d {$output} ]; then",
            "  echo \"The build finished but {$project->build_output} does not exist. Check 'Build output folder' in the project settings.\"",
            '  exit 1',
            'fi',
            "echo \"Build output: \$(find {$output} -type f | wc -l) files in {$project->build_output}\"",
        ]);
    }

    /** Unpacks an uploaded build archive into the build folder of the release. */
    public function extractBuild(string $remoteArchive): string
    {
        $output = Shell::quote($this->project->build_output);
        $archive = Shell::quote($remoteArchive);

        return implode("\n", [
            "OUT=\"\$RELEASE_PATH\"/{$output}",
            'rm -rf "$OUT"',
            'mkdir -p "$OUT"',
            "tar -xzf {$archive} -C \"\$OUT\"",
            "rm -f {$archive}",
            "echo \"Extracted \$(find \"\$OUT\" -type f | wc -l) files into {$this->project->build_output}\"",
            'if [ -f "$OUT/manifest.json" ]; then',
            '  echo "manifest.json found"',
            'else',
            '  echo "WARNING: no manifest.json in the build folder, Laravel may not find the Vite assets."',
            'fi',
        ]);
    }

    /** Gives storage/ and bootstrap/cache to the web server user (php-fpm), when we can. */
    public function permissions(): string
    {
        if (! $this->project->web_user) {
            return 'true';
        }

        $storage = in_array('storage', array_map(fn ($d) => trim($d, '/'), $this->project->sharedDirs()), true)
            ? '"$SHARED_PATH/storage"'
            : '"$RELEASE_PATH/storage"';

        return implode("\n", [
            'if id "$WEB_USER" >/dev/null 2>&1; then',
            '  if [ "$(id -u)" = "0" ]; then CHOWN="chown"; elif sudo -n true 2>/dev/null; then CHOWN="sudo -n chown"; else CHOWN=""; fi',
            '  if [ -n "$CHOWN" ]; then',
            "    for P in {$storage} \"\$RELEASE_PATH/bootstrap/cache\"; do",
            '      if [ -e "$P" ]; then $CHOWN -R "$WEB_USER":"$WEB_USER" "$P/" || echo "WARNING: could not change the owner of $P"; fi',
            '    done',
            '    echo "storage and bootstrap/cache belong to $WEB_USER"',
            '  else',
            '    echo "Not root and no sudo: storage permissions left as they are."',
            '  fi',
            'else',
            '  echo "User $WEB_USER does not exist on this server, permissions left as they are."',
            'fi',
        ]);
    }

    /** Points "current" to the release in one atomic rename (no downtime). */
    public function activate(): string
    {
        return implode("\n", [
            $this->permissions(),
            'cd "$DEPLOY_PATH"',
            'if [ ! -d "releases/$RELEASE" ]; then',
            '  echo "Release $RELEASE not found on the server."',
            '  exit 1',
            'fi',
            'PREVIOUS="$(readlink current 2>/dev/null || echo none)"',
            'ln -sfn "releases/$RELEASE" current-next',
            'mv -Tf current-next current',
            'echo "current: $PREVIOUS -> $(readlink current)"',
        ]);
    }

    /** Keeps the newest N releases (and always the live one). */
    public function cleanup(int $keep): string
    {
        $keep = max(1, $keep);

        return implode("\n", [
            'cd "$DEPLOY_PATH/releases"',
            'ACTIVE="$(basename "$(readlink "$CURRENT_PATH" 2>/dev/null || echo none)")"',
            'COUNT=0',
            'for R in $(ls -1 | sort -r); do',
            '  COUNT=$((COUNT + 1))',
            "  if [ \"\$COUNT\" -le {$keep} ] || [ \"\$R\" = \"\$ACTIVE\" ]; then continue; fi",
            '  rm -rf "$R"',
            '  echo "Removed old release $R"',
            'done',
            'rm -f "$DEPLOY_PATH"/.autodeploy/build-*.tar.gz',
            'echo "Releases kept: $(ls -1 | sort -r | tr \'\n\' \' \')"',
            'echo "'.self::RELEASES_MARKER.' $(ls -1 | sort -r | tr \'\n\' \' \')"',
            $this->permissions(),
        ]);
    }

    /** Removes the release folder of a failed deploy (never the live one). */
    public function removeRelease(): string
    {
        return implode("\n", [
            'if [ "$(readlink "$CURRENT_PATH" 2>/dev/null)" != "releases/$RELEASE" ] && [ -d "$RELEASE_PATH" ]; then',
            '  rm -rf "$RELEASE_PATH"',
            '  echo "Removed the unfinished release $RELEASE"',
            'fi',
        ]);
    }

    public function checkRelease(): string
    {
        return implode("\n", [
            'if [ ! -d "$RELEASE_PATH" ]; then',
            '  echo "Release $RELEASE is no longer on the server."',
            '  exit 1',
            'fi',
            'echo "Release $RELEASE found"',
            'echo "Live now: $(readlink "$CURRENT_PATH" 2>/dev/null || echo none)"',
        ]);
    }

    /** Lists the release folders on the server and the live one. */
    public function listReleases(): string
    {
        return implode("\n", [
            'if [ -d "$DEPLOY_PATH/releases" ]; then ls -1 "$DEPLOY_PATH/releases" | sort -r | sed \'s/^/release=/\'; fi',
            'echo "current=$(basename "$(readlink "$CURRENT_PATH" 2>/dev/null || echo)")"',
        ]);
    }

    /**
     * Release folder names after the cleanup marker in a step output.
     *
     * @return list<string>|null
     */
    public static function parseReleases(string $output): ?array
    {
        if (! preg_match('/'.self::RELEASES_MARKER.' ?([^\r\n]*)/', $output, $matches)) {
            return null;
        }

        return array_values(array_filter(explode(' ', trim($matches[1]))));
    }

    /**
     * Commit hash, author and subject printed by the clone step.
     *
     * @return array{hash: string, author: string, message: string}|null
     */
    public static function parseCommit(string $output): ?array
    {
        if (! preg_match('/'.self::COMMIT_MARKER.'\t([0-9a-f]{7,64})\t([^\t]*)\t([^\r\n]*)/', $output, $matches)) {
            return null;
        }

        return ['hash' => $matches[1], 'author' => $matches[2], 'message' => $matches[3]];
    }
}
