<template>
    <form class="space-y-8" @submit.prevent="submit">
        <!-- General -->
        <FormSection
            :title="$t('General')"
            :description="
                $t(
                    'What the project is called and which server it is deployed to.',
                )
            "
            :icon="icons.general"
        >
            <TextInput
                v-model="form.name"
                :label="$t('Name')"
                :placeholder="$t('e.g. Invoices')"
                :error="error('name')"
                required
            />

            <div v-if="servers.length">
                <SelectInput
                    v-model="form.server_id"
                    :label="$t('Server')"
                    :options="servers"
                    :error="error('server_id')"
                    required
                />
                <p
                    v-if="selectedServer && !error('server_id')"
                    class="form-hint"
                >
                    {{
                        selectedServer.memory_mb
                            ? $t(':ram of RAM', {
                                  ram: formatMegabytes(
                                      selectedServer.memory_mb,
                                  ),
                              })
                            : $t(
                                  'RAM not known yet: test the connection on the server page.',
                              )
                    }}
                </p>
            </div>
            <div
                v-else
                class="flex flex-col gap-3 rounded-lg border border-dashed border-slate-300 px-4 py-4 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between"
            >
                <span class="flex items-start gap-2.5">
                    <ServerCog class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    {{
                        $t(
                            'There is no server yet. Add the server first, then come back.',
                        )
                    }}
                </span>
                <AppButton
                    :href="serverCreateUrl"
                    size="sm"
                    variant="secondary"
                    >{{ $t('Add server') }}</AppButton
                >
            </div>

            <TextInput
                v-model="form.url"
                type="url"
                :label="$t('Site URL (optional)')"
                placeholder="https://my-app.com"
                :hint="$t('For the “Open site” button.')"
                :error="error('url')"
            />
        </FormSection>

        <!-- Repository -->
        <FormSection
            :title="$t('Repository')"
            :description="
                $t(
                    'Where the code comes from. Every deploy clones this branch into a new release.',
                )
            "
            :icon="icons.repository"
        >
            <template #aside>
                <div
                    class="mt-4 hidden rounded-lg bg-white px-3.5 py-3 text-xs leading-relaxed text-slate-500 ring-1 ring-slate-200 lg:block"
                >
                    <p class="font-medium text-slate-700">
                        {{ $t('Private repository?') }}
                    </p>
                    <p class="mt-1">
                        {{
                            $t(
                                'The server clones it with its own SSH key: add that key in GitHub as a deploy key.',
                            )
                        }}
                    </p>
                    <Link
                        v-if="form.server_id"
                        :href="serverUrl"
                        class="mt-2 inline-flex items-center gap-1 font-medium text-brand-600 hover:text-brand-700"
                    >
                        {{ $t('Show the server key') }}
                        <ChevronRight class="size-3.5" />
                    </Link>
                </div>
            </template>

            <TextInput
                v-model="form.repository"
                :label="$t('Repository')"
                placeholder="git@github.com:user/repo.git"
                :hint="
                    $t(
                        'SSH address, e.g. git@github.com:user/repo.git. The server needs read access (deploy key).',
                    )
                "
                :error="error('repository')"
                input-class="font-mono"
                required
            />
            <TextInput
                v-model="form.branch"
                :label="$t('Branch')"
                placeholder="main"
                :error="error('branch')"
                input-class="font-mono"
                required
            />
        </FormSection>

        <!-- Server paths -->
        <FormSection
            :title="$t('Server paths')"
            :description="
                $t(
                    'Where the releases live and which PHP / Composer the deploy uses.',
                )
            "
            :icon="icons.paths"
        >
            <div>
                <TextInput
                    v-model="form.deploy_path"
                    :label="$t('Deploy folder')"
                    :placeholder="suggestedPath"
                    :error="error('deploy_path')"
                    input-class="font-mono"
                    required
                />
                <p v-if="!error('deploy_path')" class="form-hint">
                    {{
                        $t(
                            'A NEW folder, e.g. /var/www/my-app. AutoDeploy creates releases/, shared/ and current inside it.',
                        )
                    }}
                    <button
                        v-if="!form.deploy_path && form.name"
                        type="button"
                        class="ml-1 font-medium text-brand-600 hover:text-brand-700"
                        @click="form.deploy_path = suggestedPath"
                    >
                        {{ $t('Use :path', { path: suggestedPath }) }}
                    </button>
                </p>
            </div>

            <PathPreview
                :deploy-path="form.deploy_path"
                :shared="sharedPreview"
            />

            <p
                class="text-brand-900 flex items-start gap-2 rounded-md bg-brand-50/70 px-3 py-2 text-xs leading-relaxed ring-1 ring-brand-100 ring-inset"
            >
                <Info class="mt-px size-3.5 shrink-0 text-brand-500" />
                <span>
                    {{ $t('Point the Nginx root of the site to') }}
                    <code class="code-inline bg-white">{{ publicPath }}</code>
                    {{ $t('so each deploy switches the site in one step.') }}
                </span>
            </p>

            <div class="space-y-2 rounded-lg border border-slate-200 p-4">
                <ToggleInput
                    v-model="form.manage_web_server"
                    :label="$t('Point the web server to current')"
                    :description="
                        $t(
                            'Right after the switch, AutoDeploy finds the Apache / Nginx site of the site URL and points its DocumentRoot / root to :path (backup, config test, reload). Needs root or passwordless sudo on the server. Off: you change it once by hand (see Quick setup).',
                            { path: publicPath },
                        )
                    "
                />
                <p
                    v-if="form.manage_web_server && !form.url"
                    class="flex items-start gap-2 text-xs text-amber-700"
                >
                    <TriangleAlert class="mt-px size-3.5 shrink-0" />
                    {{
                        $t(
                            'Needs the site URL (General): its host name tells which site to change.',
                        )
                    }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput
                    v-model="form.php_binary"
                    :label="$t('PHP binary')"
                    placeholder="php"
                    :hint="$t('e.g. php or /usr/bin/php8.4')"
                    :error="error('php_binary')"
                    input-class="font-mono"
                    required
                />
                <TextInput
                    v-model="form.composer_binary"
                    :label="$t('Composer binary')"
                    placeholder="composer"
                    :hint="$t('e.g. composer or php /usr/local/bin/composer')"
                    :error="error('composer_binary')"
                    input-class="font-mono"
                    required
                />
            </div>

            <div class="space-y-3 rounded-lg border border-slate-200 p-4">
                <ToggleInput
                    v-model="form.run_composer"
                    :label="$t('Run composer install')"
                    :description="
                        $t('Installs the PHP packages in every new release.')
                    "
                />
                <TextInput
                    v-if="form.run_composer"
                    v-model="form.composer_flags"
                    :label="$t('Composer flags')"
                    placeholder="--no-dev --optimize-autoloader --no-interaction --prefer-dist"
                    :error="error('composer_flags')"
                    input-class="font-mono text-xs"
                />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput
                    v-model="form.web_user"
                    :label="$t('Web server user')"
                    placeholder="www-data"
                    :hint="
                        $t(
                            'Gets storage/ and bootstrap/cache after each deploy. Empty = leave the owner as it is.',
                        )
                    "
                    :error="error('web_user')"
                    input-class="font-mono"
                />
                <TextInput
                    v-model="form.keep_releases"
                    type="number"
                    min="1"
                    max="20"
                    :label="$t('Releases to keep')"
                    :hint="
                        $t('How many releases are kept for rollback (1–20).')
                    "
                    :error="error('keep_releases')"
                    required
                />
            </div>
        </FormSection>

        <!-- Front-end build -->
        <FormSection
            :title="$t('Front-end build')"
            :description="
                $t(
                    'Where npm run build happens. This is the default; you can pick another one at each deploy.',
                )
            "
            :icon="icons.build"
        >
            <div>
                <span class="form-label">{{ $t('Front-end assets') }}</span>
                <StrategyCards
                    v-model="form.build_strategy"
                    :strategies="strategies"
                    :recommended="lowMemory ? 'local' : ''"
                />
                <p v-if="error('build_strategy')" class="form-error">
                    {{ error('build_strategy') }}
                </p>
            </div>

            <!-- Build locally -->
            <div
                v-if="form.build_strategy === 'local'"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            >
                <TextInput
                    v-model="form.local_path"
                    class="sm:col-span-2"
                    :label="$t('Local project folder')"
                    :placeholder="localPathExample"
                    :hint="
                        $t(
                            'The project folder on THIS computer (the one running AutoDeploy), e.g. :example',
                            { example: localPathExample },
                        )
                    "
                    :error="error('local_path')"
                    input-class="font-mono"
                    required
                />
                <TextInput
                    v-model="form.local_build_command"
                    :label="$t('Build command')"
                    placeholder="npm run build"
                    :hint="$t('Runs in the local folder.')"
                    :error="error('local_build_command')"
                    input-class="font-mono"
                    required
                />
            </div>

            <!-- Build on the server -->
            <div v-if="form.build_strategy === 'server'" class="space-y-3">
                <TextInput
                    v-model="form.server_build_command"
                    :label="$t('Build command on the server')"
                    placeholder="npm ci && npm run build"
                    :hint="
                        $t(
                            'Runs in the new release folder, before it goes live.',
                        )
                    "
                    :error="error('server_build_command')"
                    input-class="font-mono"
                    required
                />
                <p
                    v-if="lowMemory"
                    class="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{
                        $t(
                            'This server has only :ram of RAM. npm run build may get killed there; “Build locally” is safer.',
                            {
                                ram: formatMegabytes(
                                    selectedServer?.memory_mb ?? null,
                                ),
                            },
                        )
                    }}
                </p>
            </div>

            <div
                v-if="form.build_strategy !== 'none'"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            >
                <TextInput
                    v-model="form.build_output"
                    :label="$t('Build output folder')"
                    placeholder="public/build"
                    :hint="
                        $t('Inside the project, where Vite writes the assets.')
                    "
                    :error="error('build_output')"
                    input-class="font-mono"
                    required
                />
                <TextInput
                    v-if="form.build_strategy !== 'server'"
                    v-model="form.keep_builds"
                    type="number"
                    min="1"
                    max="20"
                    :label="$t('Saved builds to keep')"
                    :hint="$t('Older build archives are deleted (1–20).')"
                    :error="error('keep_builds')"
                    required
                />
            </div>
        </FormSection>

        <!-- Shared between releases -->
        <FormSection
            :title="$t('Shared between releases')"
            :description="
                $t(
                    'Kept in shared/ and symlinked into every release, so uploads, logs and the .env survive deploys.',
                )
            "
            :icon="icons.shared"
        >
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="project-shared-dirs" class="form-label">{{
                        $t('Shared folders')
                    }}</label>
                    <textarea
                        id="project-shared-dirs"
                        v-model="form.shared_dirs"
                        rows="4"
                        spellcheck="false"
                        class="form-control resize-y font-mono text-[13px]"
                        :class="{
                            'form-control-error':
                                listErrors('shared_dirs').length,
                        }"
                        placeholder="storage"
                    />
                    <p
                        v-for="message in listErrors('shared_dirs')"
                        :key="message"
                        class="form-error"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="!listErrors('shared_dirs').length"
                        class="form-hint"
                    >
                        {{ $t('One path per line, e.g. storage') }}
                    </p>
                </div>
                <div>
                    <label for="project-shared-files" class="form-label">{{
                        $t('Shared files')
                    }}</label>
                    <textarea
                        id="project-shared-files"
                        v-model="form.shared_files"
                        rows="4"
                        spellcheck="false"
                        class="form-control resize-y font-mono text-[13px]"
                        :class="{
                            'form-control-error':
                                listErrors('shared_files').length,
                        }"
                        placeholder=".env"
                    />
                    <p
                        v-for="message in listErrors('shared_files')"
                        :key="message"
                        class="form-error"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="!listErrors('shared_files').length"
                        class="form-hint"
                    >
                        {{ $t('One path per line, e.g. .env') }}
                    </p>
                </div>
            </div>
        </FormSection>

        <!-- Database backups -->
        <FormSection
            :title="$t('Database backups')"
            :description="
                $t(
                    'A copy of the database before the migrations of every deploy, so a rollback can bring the data back too.',
                )
            "
            :icon="icons.backups"
        >
            <div class="space-y-4 rounded-lg border border-slate-200 p-4">
                <ToggleInput
                    v-model="form.backup_before_deploy"
                    :label="$t('Back up the database before each deploy')"
                    :description="
                        $t(
                            'mysqldump / pg_dump (or a copy of the sqlite file) right before the migrations, into :path. If the backup fails, the deploy stops before the migrations.',
                            { path: backupsPath },
                        )
                    "
                />
                <TextInput
                    v-model="form.keep_db_backups"
                    type="number"
                    min="0"
                    max="20"
                    class="sm:max-w-xs"
                    :label="$t('Database backups to keep')"
                    :hint="
                        $t(
                            'The newest ones stay on the server (0–20, 0 = no automatic backups). Their size is on the Backups tab.',
                        )
                    "
                    :error="error('keep_db_backups')"
                    required
                />
            </div>
        </FormSection>

        <!-- Extras -->
        <FormSection
            :title="$t('Extras')"
            :description="$t('Optional checks and automation.')"
            :icon="icons.extras"
        >
            <TextInput
                v-model="form.health_check_url"
                type="url"
                :label="$t('Health check URL (optional)')"
                placeholder="https://my-app.com/up"
                :hint="
                    $t(
                        'Requested after the new release goes live; the deploy is marked failed if it does not answer with 2xx.',
                    )
                "
                :error="error('health_check_url')"
            />

            <div class="rounded-lg border border-slate-200 p-4">
                <ToggleInput
                    v-model="form.auto_deploy"
                    :label="$t('Deploy on git push (webhook)')"
                    :description="
                        $t(
                            'GitHub calls AutoDeploy after every push to the branch. The webhook URL is in the project settings, below the form.',
                        )
                    "
                />
            </div>

            <TextareaInput
                v-model="form.notes"
                :label="$t('Notes (optional)')"
                :rows="3"
                :placeholder="
                    $t('Anything worth remembering about this project…')
                "
                :error="error('notes')"
            />
        </FormSection>

        <!-- Save bar -->
        <div
            class="sticky bottom-4 z-20 flex flex-col-reverse gap-2 rounded-xl border border-slate-200 bg-white/90 px-4 py-3 shadow-lg shadow-slate-900/5 backdrop-blur sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-xs text-slate-500">
                <span
                    v-if="form.hasErrors"
                    class="inline-flex items-center gap-1.5 font-medium text-rose-600"
                >
                    <CircleAlert class="size-3.5" />
                    {{ $t('Some fields need a look.') }}
                </span>
                <span v-else-if="projectId && form.isDirty">{{
                    $t('You have unsaved changes.')
                }}</span>
                <span v-else-if="!projectId">{{
                    $t(
                        'Next: the quick setup prepares the folders on the server.',
                    )
                }}</span>
            </p>
            <div class="flex shrink-0 items-center justify-end gap-2">
                <AppButton :href="cancelHref" variant="ghost">{{
                    $t('Cancel')
                }}</AppButton>
                <AppButton
                    type="submit"
                    :icon="projectId ? icons.save : icons.create"
                    :loading="form.processing"
                    :disabled="!servers.length"
                    >{{
                        projectId ? $t('Save settings') : $t('Create project')
                    }}</AppButton
                >
            </div>
        </div>
    </form>
</template>

<script lang="ts">
import {
    ChevronRight,
    CircleAlert,
    DatabaseBackup,
    FolderPlus,
    FolderTree,
    GitBranch,
    Hammer,
    Info,
    Link2,
    Save,
    ServerCog,
    SlidersHorizontal,
    SquarePen,
    TriangleAlert,
} from '@lucide/vue';
import { Link, useForm } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import SelectInput from '@/components/inputs/SelectInput.vue';
import TextareaInput from '@/components/inputs/TextareaInput.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import ToggleInput from '@/components/inputs/ToggleInput.vue';
import FormSection from '@/components/modules/forms/FormSection.vue';
import PathPreview from '@/components/modules/projects/PathPreview.vue';
import StrategyCards from '@/components/modules/projects/StrategyCards.vue';
import { isLowMemory } from '@/components/modules/servers/usage';
import projectRoutes from '@/routes/projects';
import serverRoutes from '@/routes/servers';
import type { BuildStrategy, Card, Project } from '@/types';
import { formatMegabytes } from '@/utils/format';

/** A server in the select (ProjectController::formOptions). */
export type ServerChoice = {
    value: number;
    label: string;
    memory_mb: number | null;
    is_local: boolean;
};

/** Starting values: the project when editing, the defaults when creating. */
export type ProjectFormValues = Partial<Omit<Project, 'server_id'>> & {
    server_id?: number | null;
};

/** "storage\n/storage/\n\nbootstrap" → ["storage", "bootstrap"] (same cleanup as ProjectRequest). */
function lines(text: string): string[] {
    const items = text
        .split(/[\r\n,]+/)
        .map((line) => line.trim().replace(/^[\s/]+|[\s/]+$/g, ''))
        .filter(Boolean);

    return [...new Set(items)];
}

function slug(text: string): string {
    return (
        text
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'my-app'
    );
}

/** Create / edit a project: general, repository, server paths, front-end build, shared paths, extras. */
export default defineComponent({
    name: 'ProjectForm',

    components: {
        AppButton,
        ChevronRight,
        CircleAlert,
        FormSection,
        Info,
        Link,
        PathPreview,
        SelectInput,
        ServerCog,
        StrategyCards,
        TextareaInput,
        TextInput,
        ToggleInput,
        TriangleAlert,
    },

    props: {
        initial: {
            type: Object as PropType<ProjectFormValues>,
            default: () => ({}),
        },
        servers: { type: Array as PropType<ServerChoice[]>, default: () => [] },
        strategies: { type: Array as PropType<Card[]>, default: () => [] },
        isWindows: { type: Boolean, default: false },
        /** The project being edited (null = new project). */
        projectId: { type: Number as PropType<number | null>, default: null },
    },

    data() {
        const v = this.initial;

        return {
            icons: {
                general: markRaw(SquarePen),
                repository: markRaw(GitBranch),
                paths: markRaw(FolderTree),
                build: markRaw(Hammer),
                shared: markRaw(Link2),
                backups: markRaw(DatabaseBackup),
                extras: markRaw(SlidersHorizontal),
                save: markRaw(Save),
                create: markRaw(FolderPlus),
            },
            form: useForm({
                name: v.name ?? '',
                server_id: (v.server_id ?? this.servers[0]?.value ?? null) as
                    | number
                    | null,
                url: v.url ?? '',
                repository: v.repository ?? '',
                branch: v.branch ?? 'main',
                deploy_path: v.deploy_path ?? '',
                php_binary: v.php_binary ?? 'php',
                composer_binary: v.composer_binary ?? 'composer',
                run_composer: v.run_composer ?? true,
                composer_flags: v.composer_flags ?? '',
                web_user: v.web_user ?? '',
                manage_web_server: v.manage_web_server ?? false,
                keep_releases: (v.keep_releases ?? 3) as number | null,
                backup_before_deploy: v.backup_before_deploy ?? true,
                keep_db_backups: (v.keep_db_backups ?? 1) as number | null,
                build_strategy: (v.build_strategy ?? 'local') as BuildStrategy,
                local_path: v.local_path ?? '',
                local_build_command: v.local_build_command ?? 'npm run build',
                server_build_command:
                    v.server_build_command ?? 'npm ci && npm run build',
                build_output: v.build_output ?? 'public/build',
                keep_builds: (v.keep_builds ?? 3) as number | null,
                shared_dirs: (v.shared_dirs ?? ['storage']).join('\n'),
                shared_files: (v.shared_files ?? ['.env']).join('\n'),
                health_check_url: v.health_check_url ?? '',
                auto_deploy: v.auto_deploy ?? false,
                notes: v.notes ?? '',
            }),
        };
    },

    computed: {
        selectedServer(): ServerChoice | null {
            return (
                this.servers.find((s) => s.value === this.form.server_id) ??
                null
            );
        },

        lowMemory(): boolean {
            return isLowMemory(this.selectedServer?.memory_mb);
        },

        suggestedPath(): string {
            return `/var/www/${slug(this.form.name)}`;
        },

        publicPath(): string {
            const base = (this.form.deploy_path || this.suggestedPath).replace(
                /\/+$/,
                '',
            );

            return `${base}/current/public`;
        },

        backupsPath(): string {
            const base = (this.form.deploy_path || this.suggestedPath).replace(
                /\/+$/,
                '',
            );

            return `${base}/backups/db`;
        },

        sharedPreview(): string[] {
            return [
                ...lines(this.form.shared_files),
                ...lines(this.form.shared_dirs),
            ];
        },

        localPathExample(): string {
            return this.isWindows
                ? 'C:\\Users\\brand\\Desktop\\work\\MyApp'
                : '/home/you/projects/my-app';
        },

        serverUrl(): string {
            return this.form.server_id
                ? serverRoutes.show(this.form.server_id).url
                : '';
        },

        serverCreateUrl(): string {
            return serverRoutes.create().url;
        },

        cancelHref(): string {
            return this.projectId
                ? projectRoutes.show(this.projectId).url
                : projectRoutes.index().url;
        },

        errors(): Record<string, string | undefined> {
            return this.form.errors as Record<string, string | undefined>;
        },
    },

    methods: {
        formatMegabytes,

        error(key: string): string {
            return this.errors[key] ?? '';
        },

        /** Errors of a list field: "shared_dirs" itself and its lines ("shared_dirs.1"), with the line that is wrong. */
        listErrors(key: 'shared_dirs' | 'shared_files'): string[] {
            const values = lines(this.form[key]);

            return Object.entries(this.errors)
                .filter(
                    ([name, message]) =>
                        message && (name === key || name.startsWith(`${key}.`)),
                )
                .map(([name, message]) => {
                    const index = Number(name.split('.')[1]);
                    const value = Number.isInteger(index)
                        ? values[index]
                        : undefined;

                    return value ? `${value}: ${message}` : String(message);
                });
        },

        submit(): void {
            const route = this.projectId
                ? projectRoutes.update(this.projectId)
                : projectRoutes.store();

            this.form
                .transform((data) => ({
                    ...data,
                    shared_dirs: lines(data.shared_dirs),
                    shared_files: lines(data.shared_files),
                }))
                .submit(route, {
                    preserveScroll: true,
                    onSuccess: () => this.form.defaults(),
                    onError: () => this.revealFirstError(),
                });
        },

        revealFirstError(): void {
            this.$nextTick(() =>
                document
                    .querySelector('.form-error')
                    ?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
            );
        },
    },
});
</script>
