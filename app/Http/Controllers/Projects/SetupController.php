<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectSetup;
use App\Services\Workers\SchedulerManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Quick setup: a checklist of what the server needs, and one button that prepares it. */
class SetupController extends Controller
{
    public function show(Project $project, ProjectSetup $setup): Response
    {
        $project->loadMissing('server');

        return Inertia::render('projects/Setup', [
            ...ProjectPage::props($project),
            'checks' => Inertia::defer(fn () => $setup->check($project)),
            'nginxSnippet' => $this->nginxSnippet($project),
            'apacheSnippet' => $this->apacheSnippet($project),
            'cronLine' => SchedulerManager::cronLine($project),
            'serverKey' => $project->server->info['ssh_public_key'] ?? null,
            'deployKeysUrl' => $project->githubSlug() ? 'https://github.com/'.$project->githubSlug().'/settings/keys' : null,
        ]);
    }

    public function run(Request $request, Project $project, ProjectSetup $setup): RedirectResponse
    {
        $importFrom = $request->validate([
            'import_from' => ['nullable', 'string', 'max:255', 'starts_with:/'],
        ])['import_from'] ?? null;

        $result = $setup->run($project, $importFrom ?: null);

        Inertia::flash('output', ['title' => __('Quick setup'), 'ok' => $result['ok'], 'text' => $result['output']]);
        Inertia::flash($result['ok'] ? 'success' : 'error', $result['ok'] ? __('Quick setup finished.') : __('Quick setup stopped, see the output.'));

        return back();
    }

    /** Apache + PHP-FPM: only DocumentRoot (and Directory) change; with Let's Encrypt the -le-ssl.conf file too. */
    private function apacheSnippet(Project $project): string
    {
        $host = $project->url ? (parse_url($project->url, PHP_URL_HOST) ?: 'example.com') : 'example.com';
        $public = $project->currentPath().'/public';

        return implode("\n", [
            '<VirtualHost *:80>',
            '    ServerName '.$host,
            '    DocumentRoot '.$public,
            '',
            '    <Directory '.$public.'>',
            '        Options FollowSymLinks',
            '        AllowOverride All',
            '        Require all granted',
            '    </Directory>',
            '',
            '    # The PHP-FPM socket of the site stays the same (ls /run/php*/).',
            '    <FilesMatch \.php$>',
            '        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"',
            '    </FilesMatch>',
            '</VirtualHost>',
            '',
            '# Same DocumentRoot / <Directory> change in the HTTPS file made by certbot (…-le-ssl.conf).',
        ]);
    }

    private function nginxSnippet(Project $project): string
    {
        $host = $project->url ? (parse_url($project->url, PHP_URL_HOST) ?: 'example.com') : 'example.com';

        return implode("\n", [
            'server {',
            '    listen 80;',
            '    server_name '.$host.';',
            '    root '.$project->currentPath().'/public;',
            '',
            '    index index.php;',
            '    charset utf-8;',
            '',
            '    location / {',
            '        try_files $uri $uri/ /index.php?$query_string;',
            '    }',
            '',
            '    location ~ ^/index\.php(/|$) {',
            '        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;',
            '        fastcgi_split_path_info ^(.+\.php)(/.*)$;',
            '        include fastcgi_params;',
            '        # $realpath_root: PHP sees the real release folder, not "current".',
            '        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;',
            '        fastcgi_param DOCUMENT_ROOT $realpath_root;',
            '    }',
            '',
            '    location ~ /\.(?!well-known).* {',
            '        deny all;',
            '    }',
            '}',
        ]);
    }
}
