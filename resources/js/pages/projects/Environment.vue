<template>
    <Head :title="`${$t('Environment')} · ${project.name}`" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="environment"
    >
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="min-w-0 xl:col-span-2">
                <Deferred data="environment">
                    <!-- Loading (SSH round trip) -->
                    <template #fallback>
                        <section class="card overflow-hidden">
                            <div
                                class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4"
                            >
                                <div class="space-y-2">
                                    <div
                                        class="h-4 w-40 animate-pulse rounded bg-slate-200"
                                    />
                                    <div
                                        class="h-3 w-64 max-w-full animate-pulse rounded bg-slate-100"
                                    />
                                </div>
                                <div
                                    class="h-9 w-24 animate-pulse rounded-md bg-slate-100"
                                />
                            </div>
                            <div class="p-4">
                                <div
                                    class="space-y-2.5 rounded-lg bg-slate-950 px-4 py-4"
                                >
                                    <div
                                        v-for="(width, index) in skeletonLines"
                                        :key="index"
                                        class="h-3 animate-pulse rounded bg-slate-800"
                                        :style="{ width: `${width}%` }"
                                    />
                                </div>
                                <p
                                    class="mt-3 flex items-center gap-2 text-sm text-slate-500"
                                >
                                    <LoaderCircle
                                        class="size-4 animate-spin text-brand-500"
                                    />
                                    {{ $t('Reading .env from the server…') }}
                                </p>
                            </div>
                        </section>
                    </template>

                    <template #default="{ reloading }">
                        <!-- Could not read it -->
                        <section
                            v-if="env?.error"
                            class="card flex flex-col items-center px-6 py-12 text-center"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-full bg-rose-50 text-rose-600"
                            >
                                <ServerCrash class="size-6" />
                            </span>
                            <h2
                                class="mt-4 text-base font-semibold text-slate-900"
                            >
                                {{ $t('Could not read the .env file') }}
                            </h2>
                            <p
                                class="mt-2 max-w-lg rounded-md bg-rose-50 px-3 py-2 font-mono text-xs break-words text-rose-700"
                            >
                                {{ env.error }}
                            </p>
                            <AppButton
                                class="mt-5"
                                variant="secondary"
                                :icon="RefreshCw"
                                :loading="reloading"
                                @click="retry"
                                >{{ $t('Retry') }}</AppButton
                            >
                        </section>

                        <!-- Editor -->
                        <section v-else class="card overflow-hidden">
                            <header
                                class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-5"
                            >
                                <div class="min-w-0">
                                    <h2
                                        class="flex flex-wrap items-center gap-2 text-base font-semibold text-slate-900"
                                    >
                                        <FileKey
                                            class="size-4.5 text-slate-400"
                                        />
                                        shared/.env
                                        <span
                                            v-if="env?.exists"
                                            class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 ring-1 ring-emerald-100"
                                            >{{ $t('On the server') }}</span
                                        >
                                        <span
                                            v-else
                                            class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-amber-100"
                                            >{{ $t('Not created yet') }}</span
                                        >
                                    </h2>
                                    <p
                                        class="mt-0.5 font-mono text-xs break-all text-slate-500"
                                    >
                                        {{ path }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <AppButton
                                        variant="secondary"
                                        size="sm"
                                        :icon="KeyRound"
                                        @click="askKey"
                                        >{{ $t('Generate APP_KEY') }}</AppButton
                                    >
                                    <AppButton
                                        v-if="!env?.exists || !contents.trim()"
                                        variant="secondary"
                                        size="sm"
                                        :icon="LayoutTemplate"
                                        @click="askTemplate"
                                        >{{ $t('Laravel template') }}</AppButton
                                    >
                                </div>
                            </header>

                            <div class="space-y-3 p-4">
                                <!-- The file does not exist yet -->
                                <div
                                    v-if="!env?.exists"
                                    class="flex flex-col gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <p class="flex items-start gap-2.5">
                                        <Info
                                            class="mt-0.5 size-4 shrink-0 text-sky-600"
                                        />
                                        <span>{{
                                            $t(
                                                'The file does not exist yet: saving creates it. Paste the .env of the site, or start from a template.',
                                            )
                                        }}</span>
                                    </p>
                                    <AppButton
                                        size="sm"
                                        :icon="LayoutTemplate"
                                        class="shrink-0"
                                        @click="askTemplate"
                                        >{{
                                            $t('Start from a Laravel template')
                                        }}</AppButton
                                    >
                                </div>

                                <!-- Warnings -->
                                <div
                                    v-for="warning in warnings"
                                    :key="warning.key"
                                    class="flex flex-col gap-2 rounded-lg border px-4 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between"
                                    :class="
                                        warning.tone === 'danger'
                                            ? 'border-rose-200 bg-rose-50 text-rose-900'
                                            : 'border-amber-200 bg-amber-50 text-amber-900'
                                    "
                                >
                                    <p class="flex items-start gap-2.5">
                                        <TriangleAlert
                                            class="mt-0.5 size-4 shrink-0"
                                            :class="
                                                warning.tone === 'danger'
                                                    ? 'text-rose-600'
                                                    : 'text-amber-600'
                                            "
                                        />
                                        <span>{{ warning.text }}</span>
                                    </p>
                                    <button
                                        v-if="warning.fix"
                                        type="button"
                                        class="shrink-0 self-start rounded-md bg-white px-2.5 py-1 text-xs font-semibold shadow-xs ring-1 transition sm:self-auto"
                                        :class="
                                            warning.tone === 'danger'
                                                ? 'text-rose-700 ring-rose-200 hover:bg-rose-100'
                                                : 'text-amber-800 ring-amber-200 hover:bg-amber-100'
                                        "
                                        @click="warning.fix.action"
                                    >
                                        {{ warning.fix.label }}
                                    </button>
                                </div>

                                <textarea
                                    v-model="contents"
                                    rows="26"
                                    wrap="off"
                                    spellcheck="false"
                                    autocapitalize="off"
                                    autocomplete="off"
                                    class="code-editor min-h-80"
                                    :placeholder="
                                        'APP_NAME=' +
                                        project.name +
                                        '\nAPP_ENV=production\n…'
                                    "
                                    :aria-label="$t('.env contents')"
                                    @keydown.ctrl.s.prevent="save"
                                    @keydown.meta.s.prevent="save"
                                />
                                <p
                                    v-if="form.errors.contents"
                                    class="form-error"
                                >
                                    {{ form.errors.contents }}
                                </p>
                            </div>

                            <footer
                                class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-4 py-3 sm:px-5"
                            >
                                <p class="text-xs text-slate-500 tabular-nums">
                                    {{
                                        $t(':count lines', { count: lineCount })
                                    }}
                                    <span
                                        v-if="dirty"
                                        class="ml-2 font-medium text-amber-600"
                                        >· {{ $t('Unsaved changes') }}</span
                                    >
                                    <span
                                        v-else-if="form.recentlySuccessful"
                                        class="ml-2 font-medium text-emerald-600"
                                        >· {{ $t('Saved on the server') }}</span
                                    >
                                </p>
                                <div class="flex items-center gap-2">
                                    <AppButton
                                        v-if="dirty"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="form.processing"
                                        @click="discard"
                                        >{{ $t('Discard changes') }}</AppButton
                                    >
                                    <AppButton
                                        :icon="Save"
                                        :loading="form.processing"
                                        :disabled="!dirty"
                                        @click="save"
                                        >{{ $t('Save .env') }}</AppButton
                                    >
                                </div>
                            </footer>
                        </section>
                    </template>
                </Deferred>
            </div>

            <!-- About the file -->
            <aside class="min-w-0 space-y-6">
                <AppCard :title="$t('About this file')">
                    <ul class="space-y-3.5 text-sm text-slate-600">
                        <li class="flex gap-2.5">
                            <Link2
                                class="mt-0.5 size-4 shrink-0 text-slate-400"
                            />
                            <span>{{
                                $t(
                                    'It lives in shared/ and every release links to it, so it survives deploys and rollbacks.',
                                )
                            }}</span>
                        </li>
                        <li class="flex gap-2.5">
                            <History
                                class="mt-0.5 size-4 shrink-0 text-slate-400"
                            />
                            <span>
                                {{ $t('Saving keeps the previous version as') }}
                                <code class="code-inline">.env.backup</code>
                                {{ $t('next to it.') }}
                            </span>
                        </li>
                        <li class="flex gap-2.5">
                            <RefreshCw
                                class="mt-0.5 size-4 shrink-0 text-slate-400"
                            />
                            <span>
                                {{
                                    $t(
                                        'The site reads the new values after the next deploy, or right away after',
                                    )
                                }}
                                <code class="code-inline"
                                    >php artisan config:cache</code
                                >.
                            </span>
                        </li>
                        <li class="flex gap-2.5">
                            <ShieldCheck
                                class="mt-0.5 size-4 shrink-0 text-emerald-500"
                            />
                            <span>{{
                                $t(
                                    'It is sent over SSH and never stored in AutoDeploy.',
                                )
                            }}</span>
                        </li>
                    </ul>
                </AppCard>

                <AppCard :title="$t('Production checklist')">
                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="item in checklist"
                            :key="item.label"
                            class="flex items-center gap-2.5"
                        >
                            <CircleCheck
                                v-if="item.ok"
                                class="size-4 shrink-0 text-emerald-500"
                            />
                            <CircleDashed
                                v-else
                                class="size-4 shrink-0 text-slate-300"
                            />
                            <code
                                class="font-mono text-[12.5px]"
                                :class="
                                    item.ok
                                        ? 'text-slate-700'
                                        : 'text-slate-400'
                                "
                                >{{ item.label }}</code
                            >
                        </li>
                    </ul>
                </AppCard>
            </aside>
        </div>

        <ConfirmModal
            :show="confirming !== null"
            :title="
                confirming === 'key'
                    ? $t('Replace APP_KEY?')
                    : $t('Replace the contents?')
            "
            :message="
                confirming === 'key'
                    ? $t(
                          'A new key logs everybody out and makes data encrypted with the old key unreadable. Only replace it if you know it is not used yet.',
                      )
                    : $t(
                          'The editor is filled with a Laravel production template. What you typed is replaced (nothing is saved until you press Save).',
                      )
            "
            :confirm-label="$t('Replace')"
            :variant="confirming === 'key' ? 'danger' : 'primary'"
            @confirm="confirmed"
            @close="confirming = null"
        />
    </ProjectShell>
</template>

<script lang="ts">
import {
    CircleCheck,
    CircleDashed,
    FileKey,
    History,
    Info,
    KeyRound,
    LayoutTemplate,
    Link2,
    LoaderCircle,
    RefreshCw,
    Save,
    ServerCrash,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { Deferred, Head, router, useForm } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import projects from '@/routes/projects';
import type { Build, Card, Deployment, Project } from '@/types';

type EnvironmentState = {
    exists: boolean;
    contents: string;
    error: string | null;
};

type Warning = {
    key: string;
    tone: 'danger' | 'warning';
    text: string;
    fix?: { label: string; action: () => void };
};

/** Same text, ignoring Windows line endings and trailing blank lines (the server adds one "\n"). */
const normalize = (text: string): string =>
    text.replace(/\r\n/g, '\n').trimEnd();

/** Laravel's "php artisan key:generate": 32 random bytes, base64. */
function generateAppKey(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    let binary = '';

    bytes.forEach((byte) => (binary += String.fromCharCode(byte)));

    return `base64:${btoa(binary)}`;
}

/** Edit shared/.env on the server (loaded after the page shows: it needs an SSH round trip). */
export default defineComponent({
    name: 'ProjectsEnvironment',

    components: {
        AppButton,
        AppCard,
        CircleCheck,
        CircleDashed,
        ConfirmModal,
        Deferred,
        FileKey,
        Head,
        History,
        Info,
        Link2,
        LoaderCircle,
        ProjectShell,
        RefreshCw,
        ServerCrash,
        ShieldCheck,
        TriangleAlert,
    },

    props: {
        project: { type: Object as PropType<Project>, required: true },
        activeDeployment: {
            type: Object as PropType<Deployment | null>,
            default: null,
        },
        deployOptions: {
            type: Object as PropType<{ strategies: Card[]; builds: Build[] }>,
            required: true,
        },
        path: { type: String, required: true },
        /** Deferred: undefined until it arrives. */
        environment: {
            type: Object as PropType<EnvironmentState>,
            default: undefined,
        },
    },

    data() {
        return {
            /** What is in the editor. */
            contents: '',
            /** What is on the server (last read or saved). */
            original: '',
            loaded: false,
            confirming: null as 'key' | 'template' | null,
            form: useForm({ contents: '' }),
            skeletonLines: [42, 68, 30, 0, 55, 36, 74, 0, 48, 62, 40, 58],
            stopGuard: null as (() => void) | null,
            KeyRound: markRaw(KeyRound),
            LayoutTemplate: markRaw(LayoutTemplate),
            RefreshCw: markRaw(RefreshCw),
            Save: markRaw(Save),
        };
    },

    computed: {
        env(): EnvironmentState | null {
            return this.environment ?? null;
        },

        dirty(): boolean {
            return (
                this.loaded &&
                normalize(this.contents) !== normalize(this.original)
            );
        },

        lineCount(): number {
            return this.contents === '' ? 0 : this.contents.split('\n').length;
        },

        appKey(): string | null {
            return this.value('APP_KEY');
        },

        warnings(): Warning[] {
            if (!this.contents.trim()) {
                return [];
            }

            const warnings: Warning[] = [];

            if (!this.appKey) {
                warnings.push({
                    key: 'app_key',
                    tone: 'danger',
                    text: this.$t(
                        'APP_KEY is empty: Laravel will not start without it.',
                    ),
                    fix: {
                        label: this.$t('Generate APP_KEY'),
                        action: () => this.setKey(),
                    },
                });
            }

            if (this.value('APP_DEBUG')?.toLowerCase() === 'true') {
                warnings.push({
                    key: 'debug',
                    tone: 'danger',
                    text: this.$t(
                        'APP_DEBUG=true shows error pages with secrets to every visitor. Use false in production.',
                    ),
                    fix: {
                        label: this.$t('Set to false'),
                        action: () => this.setValue('APP_DEBUG', 'false'),
                    },
                });
            }

            const env = this.value('APP_ENV');

            if (env && env !== 'production') {
                warnings.push({
                    key: 'env',
                    tone: 'warning',
                    text: this.$t(
                        'APP_ENV is “:env”. A live site usually uses production.',
                        { env },
                    ),
                    fix: {
                        label: this.$t('Set to production'),
                        action: () => this.setValue('APP_ENV', 'production'),
                    },
                });
            }

            return warnings;
        },

        checklist(): { label: string; ok: boolean }[] {
            const has = (name: string) => !!this.value(name);

            return [
                {
                    label: 'APP_ENV=production',
                    ok: this.value('APP_ENV') === 'production',
                },
                {
                    label: 'APP_DEBUG=false',
                    ok: this.value('APP_DEBUG')?.toLowerCase() === 'false',
                },
                { label: 'APP_KEY', ok: !!this.appKey },
                { label: 'APP_URL', ok: has('APP_URL') },
                {
                    label: 'DB_DATABASE',
                    ok:
                        has('DB_DATABASE') ||
                        this.value('DB_CONNECTION') === 'sqlite',
                },
                {
                    label: 'DB_PASSWORD',
                    ok:
                        has('DB_PASSWORD') ||
                        this.value('DB_CONNECTION') === 'sqlite',
                },
            ];
        },
    },

    watch: {
        environment: {
            immediate: true,
            handler(env: EnvironmentState | undefined) {
                if (!env || env.error) {
                    return;
                }

                // Keep what the user typed if it differs from the new server version.
                const keepEdits =
                    this.dirty &&
                    normalize(this.contents) !== normalize(env.contents);

                this.original = env.contents;

                if (!keepEdits) {
                    this.contents = env.contents;
                }

                this.loaded = true;
            },
        },
    },

    mounted() {
        window.addEventListener('beforeunload', this.onBeforeUnload);

        // Ask before leaving the page with unsaved changes.
        this.stopGuard = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (
                this.dirty &&
                visit.method === 'get' &&
                visit.url.pathname !== window.location.pathname
            ) {
                return window.confirm(
                    this.$t(
                        'The .env has unsaved changes. Leave without saving?',
                    ),
                );
            }
        });
    },

    beforeUnmount() {
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.stopGuard?.();
    },

    methods: {
        onBeforeUnload(event: BeforeUnloadEvent): void {
            if (this.dirty) {
                event.preventDefault();
            }
        },

        /** Value of KEY=value in the editor (quotes removed), null when missing or empty. */
        value(name: string): string | null {
            const match = this.contents.match(
                new RegExp(`^[ \\t]*${name}[ \\t]*=(.*)$`, 'm'),
            );

            if (!match) {
                return null;
            }

            let value = match[1].trim();

            if (/^(["']).*\1$/.test(value)) {
                value = value.slice(1, -1);
            } else {
                value = value.replace(/\s+#.*$/, '');
            }

            return value.trim() || null;
        },

        /** Replaces KEY=… or adds it (after APP_ENV, or at the top). */
        setValue(name: string, value: string): void {
            const line = `${name}=${value}`;
            const existing = new RegExp(`^[ \\t]*${name}[ \\t]*=.*$`, 'm');

            if (existing.test(this.contents)) {
                this.contents = this.contents.replace(existing, line);

                return;
            }

            const anchor = /^[ \t]*APP_ENV[ \t]*=.*$/m;

            if (anchor.test(this.contents)) {
                this.contents = this.contents.replace(
                    anchor,
                    (found) => `${found}\n${line}`,
                );
            } else {
                this.contents = this.contents
                    ? `${line}\n${this.contents}`
                    : `${line}\n`;
            }
        },

        askKey(): void {
            if (this.appKey) {
                this.confirming = 'key';
            } else {
                this.setKey();
            }
        },

        setKey(): void {
            this.setValue('APP_KEY', generateAppKey());
        },

        askTemplate(): void {
            if (this.contents.trim()) {
                this.confirming = 'template';
            } else {
                this.useTemplate();
            }
        },

        confirmed(): void {
            if (this.confirming === 'key') {
                this.setKey();
            } else if (this.confirming === 'template') {
                this.useTemplate();
            }

            this.confirming = null;
        },

        useTemplate(): void {
            const p = this.project;
            const url = (p.url || 'https://example.com').replace(/\/+$/, '');
            let host = 'example.com';

            try {
                host = new URL(url).hostname.replace(/^www\./, '');
            } catch {
                // keep the example host
            }

            const database = p.slug.replace(/[^a-z0-9]+/gi, '_').toLowerCase();

            this.contents = [
                `APP_NAME="${p.name.replace(/"/g, '')}"`,
                'APP_ENV=production',
                `APP_KEY=${generateAppKey()}`,
                'APP_DEBUG=false',
                `APP_URL=${url}`,
                '',
                'APP_LOCALE=en',
                'APP_FALLBACK_LOCALE=en',
                'APP_FAKER_LOCALE=en_US',
                '',
                'APP_MAINTENANCE_DRIVER=file',
                'BCRYPT_ROUNDS=12',
                '',
                'LOG_CHANNEL=stack',
                'LOG_STACK=daily',
                'LOG_DEPRECATIONS_CHANNEL=null',
                'LOG_LEVEL=error',
                '',
                'DB_CONNECTION=mysql',
                'DB_HOST=127.0.0.1',
                'DB_PORT=3306',
                `DB_DATABASE=${database}`,
                `DB_USERNAME=${database}`,
                'DB_PASSWORD=',
                '',
                'SESSION_DRIVER=file',
                'SESSION_LIFETIME=120',
                'SESSION_ENCRYPT=false',
                'SESSION_PATH=/',
                'SESSION_DOMAIN=null',
                '',
                'BROADCAST_CONNECTION=log',
                'FILESYSTEM_DISK=local',
                'QUEUE_CONNECTION=database',
                '',
                'CACHE_STORE=file',
                '',
                'MAIL_MAILER=smtp',
                'MAIL_SCHEME=null',
                'MAIL_HOST=127.0.0.1',
                'MAIL_PORT=587',
                'MAIL_USERNAME=null',
                'MAIL_PASSWORD=null',
                `MAIL_FROM_ADDRESS="hello@${host}"`,
                'MAIL_FROM_NAME="${APP_NAME}"',
                '',
                'VITE_APP_NAME="${APP_NAME}"',
                '',
            ].join('\n');
        },

        discard(): void {
            this.contents = this.original;
            this.form.clearErrors();
        },

        save(): void {
            if (this.form.processing || !this.dirty) {
                return;
            }

            this.form.contents = this.contents;

            // The file is read back in the same request: "original" (and so the
            // unsaved state) follows what is really on the server, also when the
            // write failed and the controller only flashed an error.
            this.form.put(projects.environment.update(this.project.id).url, {
                preserveScroll: true,
                only: ['environment'],
            });
        },

        retry(): void {
            router.reload({ only: ['environment'] });
        },
    },
});
</script>
