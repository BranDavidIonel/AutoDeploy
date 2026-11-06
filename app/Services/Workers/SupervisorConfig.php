<?php

namespace App\Services\Workers;

use App\Models\Worker;

/** The Supervisor program block that keeps a queue worker running. */
class SupervisorConfig
{
    public static function render(Worker $worker): string
    {
        $project = $worker->project;
        $user = $worker->user ?: ($project->web_user ?: 'root');

        return implode("\n", [
            '; Managed by AutoDeploy ('.$project->name.' / '.$worker->name.'). Changes here are overwritten.',
            '[program:'.$worker->programName().']',
            'process_name=%(program_name)s_%(process_num)02d',
            'command='.$worker->command(),
            'directory='.$project->currentPath(),
            'user='.$user,
            'numprocs='.max(1, $worker->processes),
            'autostart=true',
            'autorestart=true',
            'stopasgroup=true',
            'killasgroup=true',
            'stopwaitsecs='.($worker->timeout + 10),
            'redirect_stderr=true',
            'stdout_logfile='.$worker->logPath(),
            'stdout_logfile_maxbytes=5MB',
            'stdout_logfile_backups=3',
            '',
        ]);
    }
}
