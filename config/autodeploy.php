<?php

return [

    /*
    | Languages offered by the switch in the top bar (the texts live in lang/*.json).
    */
    'locales' => [
        'en' => 'English',
        'ro' => 'Română',
    ],

    /*
    | The admin account created by the database seeder. Keep the real values in .env only.
    | An empty password makes the seeder create a random one and print it once.
    */
    'admin' => [
        'email' => env('AUTODEPLOY_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('AUTODEPLOY_ADMIN_PASSWORD'),
    ],

    /*
    | Bash used by servers of type "This machine" (deploys on the computer that runs AutoDeploy).
    | Empty = auto-detect (/bin/bash on Linux, Git Bash on Windows).
    */
    'local_shell' => env('AUTODEPLOY_LOCAL_SHELL'),

    /*
    | Seconds to wait for an SSH connection, and the longest a single deploy command may run.
    */
    'connect_timeout' => (int) env('AUTODEPLOY_CONNECT_TIMEOUT', 20),
    'command_timeout' => (int) env('AUTODEPLOY_COMMAND_TIMEOUT', 3600),

    /*
    | Longest a local build ("npm run build" on this computer) may run, in seconds.
    */
    'local_build_timeout' => (int) env('AUTODEPLOY_LOCAL_BUILD_TIMEOUT', 1800),

    /*
    | Largest build archive (.zip / .tar.gz) that can be uploaded from the browser, in MB.
    | PHP's upload_max_filesize and post_max_size must allow it too.
    */
    'max_upload_mb' => (int) env('AUTODEPLOY_MAX_UPLOAD_MB', 200),

];
