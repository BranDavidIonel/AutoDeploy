// Shapes of the data the controllers send (app/Http/Resources/*).

export type Option = { value: string | number; label: string };

export type Card = { value: string; label: string; description: string };

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        per_page: number;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export type ServerInfo = Partial<
    Record<
        | 'hostname'
        | 'user'
        | 'os'
        | 'kernel'
        | 'cpus'
        | 'mem_total'
        | 'mem_used'
        | 'mem_available'
        | 'swap_total'
        | 'swap_used'
        | 'disk_total'
        | 'disk_used'
        | 'disk_available'
        | 'uptime'
        | 'load'
        | 'php'
        | 'composer'
        | 'node'
        | 'npm'
        | 'git'
        | 'nginx'
        | 'mysql'
        | 'supervisor'
        | 'ssh_public_key',
        string
    >
>;

export type Server = {
    id: number;
    name: string;
    connection_type: 'ssh' | 'local';
    is_local: boolean;
    host: string | null;
    port: number;
    username: string;
    auth_method: 'key_file' | 'key' | 'password';
    key_path: string | null;
    has_private_key: boolean;
    has_passphrase: boolean;
    has_password: boolean;
    host_fingerprint: string | null;
    address: string;
    status: 'unknown' | 'connected' | 'failed';
    last_checked_at: string | null;
    last_error: string | null;
    info: ServerInfo;
    memory_mb: number | null;
    projects_count?: number;
};

export type BuildStrategy = 'local' | 'upload' | 'server' | 'none';

export type DeploymentStatus =
    | 'pending'
    | 'running'
    | 'finished'
    | 'failed'
    | 'cancelled';

export type StepStatus =
    | 'pending'
    | 'running'
    | 'finished'
    | 'failed'
    | 'skipped';

/** AutoDeploy's own queue worker (app/Services/Queue/QueueWorkerMonitor.php). */
export type QueueWorkerStatus =
    | 'running'
    | 'starting'
    | 'failed'
    | 'off'
    | 'stopped';

export type DeploymentStep = {
    id: number;
    key: string;
    name: string;
    is_hook: boolean;
    status: StepStatus;
    script: string | null;
    output: string;
    started_at: string | null;
    finished_at: string | null;
    duration: number | null;
};

/** deploy / rollback change the code; backup / restore only the database (app/Enums/DeploymentType.php). */
export type DeploymentType = 'deploy' | 'rollback' | 'backup' | 'restore';

export type Deployment = {
    id: number;
    project_id: number;
    type: DeploymentType;
    type_label: string;
    /** The database backup a restore (or a rollback with the database) puts back. */
    restore_backup_id: number | null;
    status: DeploymentStatus;
    status_label: string;
    is_active: boolean;
    triggered_by: 'manual' | 'webhook';
    branch: string | null;
    commit_hash: string | null;
    short_commit: string | null;
    commit_message: string | null;
    commit_author: string | null;
    release: string | null;
    release_exists: boolean;
    is_live: boolean;
    build_source: BuildStrategy | null;
    build_source_label: string | null;
    build_id: number | null;
    note: string | null;
    error: string | null;
    cancel_requested: boolean;
    user?: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    duration: number | null;
    project?: {
        id: number;
        name: string;
        slug: string;
        url: string | null;
        github_slug: string | null;
    };
    steps?: DeploymentStep[];
};

export type Project = {
    id: number;
    server_id: number;
    name: string;
    slug: string;
    url: string | null;
    repository: string;
    github_slug: string | null;
    branch: string;
    deploy_path: string;
    php_binary: string;
    composer_binary: string;
    run_composer: boolean;
    composer_flags: string;
    keep_releases: number;
    /** Every deploy backs up the database before its migrations. */
    backup_before_deploy: boolean;
    /** Database backups kept on the server (0 = no automatic backups). */
    keep_db_backups: number;
    build_strategy: BuildStrategy;
    build_strategy_label: string;
    server_build_command: string;
    local_path: string | null;
    local_path_exists: boolean;
    local_build_command: string;
    build_output: string;
    keep_builds: number;
    shared_dirs: string[];
    shared_files: string[];
    web_user: string | null;
    /** Deploys point the Apache / Nginx site of the URL host to current/public. */
    manage_web_server: boolean;
    health_check_url: string | null;
    auto_deploy: boolean;
    scheduler_enabled: boolean;
    active_release: string | null;
    last_deployed_at: string | null;
    /** Never deployed yet: the first deploy needs Quick setup first. */
    first_deploy: boolean;
    notes: string | null;
    paths: {
        current: string;
        releases: string;
        shared: string;
        public: string;
        backups: string;
    };
    server?: Server;
    latest_deployment?: Deployment | null;
};

export type Build = {
    id: number;
    source: 'upload' | 'local';
    source_label: string;
    label: string | null;
    display_name: string;
    original_name: string | null;
    size: number;
    file_count: number;
    has_manifest: boolean;
    commit_hash: string | null;
    short_commit: string | null;
    branch: string | null;
    user?: string | null;
    created_at: string;
};

export type BackupKind = 'before_deploy' | 'manual' | 'before_restore';

/** A database backup kept on the server (app/Http/Resources/BackupResource.php). */
export type Backup = {
    id: number;
    kind: BackupKind;
    kind_label: string;
    file: string;
    file_name: string;
    size: number;
    db_connection: string;
    database: string | null;
    release: string | null;
    note: string | null;
    /** The deployment that made it (the deploy for "before deploy" backups). */
    deployment_id: number | null;
    deployment_type?: DeploymentType | null;
    created_at: string;
};

export type HookStage = 'before_build' | 'before_activate' | 'after_activate';

export type Hook = {
    id: number;
    name: string;
    stage: HookStage;
    script: string;
    enabled: boolean;
    sort: number;
};

export type HookPreset = {
    key: string;
    name: string;
    stage: HookStage;
    script: string;
    description: string;
    default: boolean;
};

export type WorkerStatus =
    | 'not_installed'
    | 'running'
    | 'starting'
    | 'stopped'
    | 'fatal'
    | 'unknown';

export type Worker = {
    id: number;
    name: string;
    connection: string;
    queue: string;
    processes: number;
    timeout: number;
    sleep: number;
    tries: number;
    max_time: number;
    memory: number;
    user: string | null;
    status: WorkerStatus;
    status_output: string | null;
    installed_at: string | null;
    checked_at: string | null;
    program_name: string;
    command: string;
    config_path: string;
    config: string;
};

/** Props shared by every project tab (app/Http/Controllers/Projects/ProjectPage.php). */
export type ProjectPageProps = {
    project: Project;
    activeDeployment: Deployment | null;
    deployOptions: { strategies: Card[]; builds: Build[] };
};

/** Output of a server command, flashed by the controllers ("output"). */
export type CommandOutput = { title: string; ok: boolean; text: string };
