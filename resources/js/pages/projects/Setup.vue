<template>
    <Head :title="`${$t('Quick setup')} · ${project.name}`" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="setup"
    >
        <!-- Run quick setup -->
        <section
            class="relative mb-6 overflow-hidden rounded-2xl bg-linear-to-br from-brand-600 via-brand-600 to-violet-600 p-5 text-white shadow-lg shadow-brand-600/20 sm:p-7"
        >
            <div
                class="pointer-events-none absolute -top-20 -left-10 size-64 rounded-full bg-white/10 blur-3xl"
            />
            <div
                class="pointer-events-none absolute -right-16 -bottom-24 size-72 rounded-full bg-violet-400/25 blur-3xl"
            />

            <div
                class="relative grid grid-cols-1 gap-6 lg:grid-cols-5 lg:items-center"
            >
                <div class="min-w-0 lg:col-span-3">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-white/15 ring-1 ring-white/20"
                        >
                            <Zap class="size-5" />
                        </span>
                        <h2 class="text-lg font-semibold tracking-tight">
                            {{ $t('Run quick setup') }}
                        </h2>
                    </div>
                    <p class="mt-3 text-sm leading-relaxed text-white/85">
                        {{
                            $t(
                                'Prepares the server for zero-downtime deploys. Safe to run again: it never overwrites shared/.env or shared/storage.',
                            )
                        }}
                    </p>
                    <ul
                        class="mt-3 grid gap-2 text-sm text-white/90 sm:grid-cols-2"
                    >
                        <li
                            v-for="item in setupSteps"
                            :key="item"
                            class="flex items-start gap-2"
                        >
                            <CircleCheck
                                class="mt-0.5 size-4 shrink-0 text-emerald-300"
                            />
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </div>

                <form
                    class="shadow-brand-900/20 min-w-0 rounded-xl bg-white p-4 text-slate-900 shadow-xl lg:col-span-2"
                    @submit.prevent="runSetup"
                >
                    <TextInput
                        v-model="form.import_from"
                        :label="
                            $t(
                                'Import .env and storage from an existing folder (optional)',
                            )
                        "
                        placeholder="/var/www/my-site"
                        input-class="font-mono"
                        :hint="
                            $t(
                                'The folder of the site as it runs today. Copied once, only what shared/ does not have yet.',
                            )
                        "
                        :error="form.errors.import_from"
                    />
                    <AppButton
                        type="submit"
                        class="mt-4 w-full"
                        :icon="Zap"
                        :loading="form.processing"
                        >{{
                            form.processing
                                ? $t('Setting up the server…')
                                : $t('Run quick setup')
                        }}</AppButton
                    >
                </form>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">
                <!-- Checklist -->
                <AppCard
                    :title="$t('Server checklist')"
                    :description="
                        $t('What the server has for :name, checked over SSH.', {
                            name: project.name,
                        })
                    "
                    flush
                >
                    <template #actions>
                        <AppButton
                            variant="secondary"
                            size="sm"
                            :icon="RefreshCw"
                            :loading="checking"
                            :disabled="checks === undefined"
                            @click="checkAgain"
                            >{{ $t('Check again') }}</AppButton
                        >
                    </template>

                    <Deferred data="checks">
                        <template #fallback>
                            <div class="px-4 py-4 sm:px-5">
                                <p
                                    class="flex items-center gap-2 text-sm text-slate-500"
                                >
                                    <LoaderCircle
                                        class="size-4 animate-spin text-brand-500"
                                    />
                                    {{ $t('Checking the server…') }}
                                </p>
                                <div
                                    class="mt-3 h-1.5 animate-pulse rounded-full bg-slate-100"
                                />
                            </div>
                            <ul
                                class="divide-y divide-slate-100 border-t border-slate-100"
                            >
                                <li
                                    v-for="index in 8"
                                    :key="index"
                                    class="flex items-center gap-3 px-4 py-3.5 sm:px-5"
                                >
                                    <span
                                        class="size-6 shrink-0 animate-pulse rounded-full bg-slate-100"
                                    />
                                    <span
                                        class="h-3.5 animate-pulse rounded bg-slate-100"
                                        :style="{
                                            width: `${30 + ((index * 17) % 35)}%`,
                                        }"
                                    />
                                    <span
                                        class="ml-auto h-3 w-20 animate-pulse rounded bg-slate-100"
                                    />
                                </li>
                            </ul>
                        </template>

                        <template #default="{ reloading }">
                            <div
                                :class="{
                                    'pointer-events-none opacity-50 transition-opacity':
                                        reloading,
                                }"
                            >
                                <div
                                    v-if="checks && !checks.ok"
                                    class="flex flex-col items-center px-6 py-10 text-center"
                                >
                                    <span
                                        class="flex size-12 items-center justify-center rounded-full bg-rose-50 text-rose-600"
                                    >
                                        <ServerCrash class="size-6" />
                                    </span>
                                    <h3
                                        class="mt-4 text-base font-semibold text-slate-900"
                                    >
                                        {{ $t('Could not check the server') }}
                                    </h3>
                                    <p
                                        class="mt-2 max-w-lg rounded-md bg-rose-50 px-3 py-2 font-mono text-xs break-words text-rose-700"
                                    >
                                        {{ checks.error }}
                                    </p>
                                    <AppButton
                                        :href="serverUrl"
                                        variant="secondary"
                                        size="sm"
                                        class="mt-4"
                                        :icon="Server"
                                        >{{
                                            $t('Open the server page')
                                        }}</AppButton
                                    >
                                </div>
                                <SetupChecklist
                                    v-else-if="checks"
                                    :project="project"
                                    :checks="checks.checks"
                                />
                            </div>
                        </template>
                    </Deferred>
                </AppCard>

                <!-- Web server (Apache or Nginx) -->
                <AppCard
                    id="web-server"
                    class="scroll-mt-6"
                    :title="$t('Web server')"
                    :description="
                        $t(
                            'The web server must serve the live release through the current link.',
                        )
                    "
                >
                    <template #actions>
                        <div
                            class="flex rounded-lg bg-slate-100 p-0.5 text-sm font-medium"
                        >
                            <button
                                v-for="option in webServerOptions"
                                :key="option"
                                type="button"
                                class="rounded-md px-3 py-1 transition"
                                :class="
                                    webServer === option
                                        ? 'bg-white text-slate-900 shadow-xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                "
                                @click="webServer = option"
                            >
                                {{ option === 'apache' ? 'Apache' : 'Nginx' }}
                            </button>
                        </div>
                    </template>

                    <!-- Apache -->
                    <template v-if="webServer === 'apache'">
                        <ul class="mb-4 space-y-2 text-sm text-slate-600">
                            <li class="flex gap-2.5">
                                <ArrowRight
                                    class="mt-0.5 size-4 shrink-0 text-brand-500"
                                />
                                <span>
                                    {{ $t('DocumentRoot must point to') }}
                                    <code class="code-inline break-all">{{
                                        project.paths.public
                                    }}</code>
                                    {{
                                        $t(
                                            'so a deploy switches the site by switching the link.',
                                        )
                                    }}
                                </span>
                            </li>
                            <li class="flex gap-2.5">
                                <ArrowRight
                                    class="mt-0.5 size-4 shrink-0 text-brand-500"
                                />
                                <span>{{
                                    $t(
                                        'Change it in both files of the site: the one for http and the -le-ssl.conf made by certbot. The PHP-FPM socket (SetHandler) stays the same.',
                                    )
                                }}</span>
                            </li>
                            <li class="flex gap-2.5">
                                <ArrowRight
                                    class="mt-0.5 size-4 shrink-0 text-brand-500"
                                />
                                <span>{{
                                    $t(
                                        'Keep “Reload PHP-FPM” on in the deploy script: it clears OPcache after every switch.',
                                    )
                                }}</span>
                            </li>
                        </ul>

                        <LogOutput
                            :text="apacheSnippet"
                            :title="`/etc/apache2/sites-available/${project.slug}.conf`"
                            max-height="max-h-[32rem]"
                        />

                        <p class="mt-4 text-sm text-slate-600">
                            {{ $t('Then test the config and reload Apache:') }}
                        </p>
                        <div
                            class="mt-1.5 flex items-center justify-between gap-2 rounded-lg bg-slate-950 py-1.5 pr-1.5 pl-3.5 font-mono text-[12.5px] text-slate-100"
                        >
                            <code class="min-w-0 break-all">{{
                                reloadApache
                            }}</code>
                            <CopyButton
                                :text="reloadApache"
                                dark
                                class="shrink-0"
                            />
                        </div>
                    </template>

                    <!-- Nginx -->
                    <template v-else>
                        <ul class="mb-4 space-y-2 text-sm text-slate-600">
                            <li class="flex gap-2.5">
                                <ArrowRight
                                    class="mt-0.5 size-4 shrink-0 text-brand-500"
                                />
                                <span>
                                    {{ $t('root must point to') }}
                                    <code class="code-inline break-all">{{
                                        project.paths.public
                                    }}</code>
                                    {{
                                        $t(
                                            'so a deploy switches the site by switching the link.',
                                        )
                                    }}
                                </span>
                            </li>
                            <li class="flex gap-2.5">
                                <ArrowRight
                                    class="mt-0.5 size-4 shrink-0 text-brand-500"
                                />
                                <span>
                                    <code class="code-inline"
                                        >$realpath_root</code
                                    >
                                    {{
                                        $t(
                                            'makes PHP see the real release folder, so OPcache never serves the old release.',
                                        )
                                    }}
                                </span>
                            </li>
                            <li class="flex gap-2.5">
                                <ArrowRight
                                    class="mt-0.5 size-4 shrink-0 text-brand-500"
                                />
                                <span>
                                    {{
                                        $t(
                                            'Adjust fastcgi_pass to your PHP-FPM version (see',
                                        )
                                    }}
                                    <code class="code-inline">ls /run/php/</code
                                    >).
                                </span>
                            </li>
                        </ul>

                        <LogOutput
                            :text="nginxSnippet"
                            :title="`/etc/nginx/sites-available/${project.slug}`"
                            max-height="max-h-[32rem]"
                        />

                        <p class="mt-4 text-sm text-slate-600">
                            {{ $t('Then test the config and reload Nginx:') }}
                        </p>
                        <div
                            class="mt-1.5 flex items-center justify-between gap-2 rounded-lg bg-slate-950 py-1.5 pr-1.5 pl-3.5 font-mono text-[12.5px] text-slate-100"
                        >
                            <code class="min-w-0 break-all">{{
                                reloadNginx
                            }}</code>
                            <CopyButton
                                :text="reloadNginx"
                                dark
                                class="shrink-0"
                            />
                        </div>
                    </template>
                </AppCard>
            </div>

            <aside class="min-w-0 space-y-6">
                <!-- Deploy key -->
                <AppCard
                    id="ssh-key"
                    class="scroll-mt-6"
                    :title="$t('Server SSH key → GitHub deploy key')"
                >
                    <template v-if="serverKey">
                        <p class="text-sm leading-relaxed text-slate-600">
                            {{
                                $t(
                                    'The server clones the repository with this key. Add it to the repository as a deploy key (read access is enough).',
                                )
                            }}
                        </p>
                        <div
                            class="mt-3 rounded-lg bg-slate-950 ring-1 ring-slate-800"
                        >
                            <div
                                class="flex items-center justify-between border-b border-white/5 py-1.5 pr-1.5 pl-3.5"
                            >
                                <span class="font-mono text-xs text-slate-400"
                                    >~/.ssh/*.pub</span
                                >
                                <CopyButton
                                    :text="serverKey"
                                    :label="$t('Copy')"
                                    dark
                                />
                            </div>
                            <p
                                class="max-h-32 overflow-y-auto px-3.5 py-2.5 font-mono text-[11.5px] leading-relaxed break-all text-slate-300"
                            >
                                {{ serverKey }}
                            </p>
                        </div>
                        <ol class="mt-3 space-y-1 text-xs text-slate-500">
                            <li>1. {{ $t('Copy the key.') }}</li>
                            <li>
                                2.
                                {{
                                    $t(
                                        'GitHub → repository → Settings → Deploy keys → Add deploy key.',
                                    )
                                }}
                            </li>
                            <li>
                                3.
                                {{
                                    $t(
                                        'Paste it and leave “Allow write access” off.',
                                    )
                                }}
                            </li>
                        </ol>
                        <AppButton
                            v-if="deployKeysUrl"
                            :href="deployKeysUrl"
                            external
                            variant="secondary"
                            size="sm"
                            class="mt-4 w-full"
                            :icon="ExternalLink"
                            >{{ $t('Open deploy keys on GitHub') }}</AppButton
                        >
                        <p v-else class="mt-3 text-xs text-slate-500">
                            {{
                                $t(
                                    'GitLab or Bitbucket: add it as a read-only deploy key in the repository settings.',
                                )
                            }}
                        </p>
                    </template>
                    <div v-else class="text-sm text-slate-600">
                        <p class="flex items-start gap-2.5">
                            <KeyRound
                                class="mt-0.5 size-4 shrink-0 text-amber-500"
                            />
                            <span>{{
                                $t(
                                    'AutoDeploy does not know the SSH key of this server yet. Create one (or read the existing one) on the server page, then add it here as a deploy key.',
                                )
                            }}</span>
                        </p>
                        <AppButton
                            :href="serverUrl"
                            variant="secondary"
                            size="sm"
                            class="mt-4 w-full"
                            :icon="Server"
                            >{{ $t('Open the server page') }}</AppButton
                        >
                    </div>
                </AppCard>

                <!-- Scheduler -->
                <AppCard :title="$t('Scheduler cron')">
                    <template #actions>
                        <StatusBadge
                            :status="
                                project.scheduler_enabled
                                    ? 'enabled'
                                    : 'disabled'
                            "
                        />
                    </template>
                    <p class="text-sm leading-relaxed text-slate-600">
                        {{
                            $t(
                                'If the app uses Laravel’s scheduler, this line must run every minute:',
                            )
                        }}
                    </p>
                    <div
                        class="mt-3 flex items-start gap-2 rounded-lg bg-slate-950 py-2 pr-1.5 pl-3.5"
                    >
                        <code
                            class="min-w-0 flex-1 py-0.5 font-mono text-[11.5px] leading-relaxed break-all text-slate-200"
                            >{{ cronLine }}</code
                        >
                        <CopyButton :text="cronLine" dark class="shrink-0" />
                    </div>
                    <Link
                        :href="workersUrl"
                        class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-700"
                    >
                        {{
                            project.scheduler_enabled
                                ? $t('Manage it on the Workers tab')
                                : $t(
                                      'Enable it with one click on the Workers tab',
                                  )
                        }}
                        <ChevronRight class="size-4" />
                    </Link>
                </AppCard>

                <!-- Moving an existing site -->
                <AppCard :title="$t('Moving an existing site')">
                    <ol class="space-y-3">
                        <li
                            v-for="(step, index) in moveSteps"
                            :key="index"
                            class="flex gap-3 text-sm text-slate-600"
                        >
                            <span
                                class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 ring-1 ring-brand-100"
                                >{{ index + 1 }}</span
                            >
                            <span
                                class="min-w-0 pt-0.5 [overflow-wrap:anywhere]"
                            >
                                {{ step.text }}
                                <Link
                                    v-if="step.link"
                                    :href="step.link.href"
                                    class="font-medium whitespace-nowrap text-brand-600 hover:text-brand-700"
                                    >{{ step.link.label }} →</Link
                                >
                            </span>
                        </li>
                    </ol>
                    <p
                        class="mt-4 flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-xs leading-relaxed text-emerald-800 ring-1 ring-emerald-100"
                    >
                        <ShieldCheck class="mt-px size-4 shrink-0" />
                        {{
                            $t(
                                'The old folder is not touched: it stays as a backup. Point the web server back to it if anything goes wrong, and delete it once the new setup works.',
                            )
                        }}
                    </p>
                </AppCard>
            </aside>
        </div>
    </ProjectShell>
</template>

<script lang="ts">
import {
    ArrowRight,
    ChevronRight,
    CircleCheck,
    ExternalLink,
    KeyRound,
    LoaderCircle,
    RefreshCw,
    Server,
    ServerCrash,
    ShieldCheck,
    Zap,
} from '@lucide/vue';
import { Deferred, Head, Link, router, useForm } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import CopyButton from '@/components/general/CopyButton.vue';
import LogOutput from '@/components/general/LogOutput.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import SetupChecklist from '@/components/modules/projects/SetupChecklist.vue';
import projects from '@/routes/projects';
import servers from '@/routes/servers';
import type { Build, Card, Deployment, Project } from '@/types';

type ChecksResult = {
    ok: boolean;
    error: string | null;
    checks: Record<string, string>;
};

/** Quick setup: one button that prepares the server, a checklist, deploy key, Nginx and cron. */
export default defineComponent({
    name: 'ProjectsSetup',

    components: {
        AppButton,
        AppCard,
        ArrowRight,
        ChevronRight,
        CircleCheck,
        CopyButton,
        Deferred,
        Head,
        KeyRound,
        Link,
        LoaderCircle,
        LogOutput,
        ProjectShell,
        ServerCrash,
        SetupChecklist,
        ShieldCheck,
        StatusBadge,
        TextInput,
        Zap,
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
        /** Deferred: undefined until the SSH check is done. */
        checks: {
            type: Object as PropType<ChecksResult>,
            default: undefined,
        },
        nginxSnippet: { type: String, required: true },
        apacheSnippet: { type: String, required: true },
        cronLine: { type: String, required: true },
        serverKey: { type: String as PropType<string | null>, default: null },
        deployKeysUrl: {
            type: String as PropType<string | null>,
            default: null,
        },
    },

    data() {
        return {
            form: useForm({ import_from: '' }),
            checking: false,
            reloadNginx: 'sudo nginx -t && sudo systemctl reload nginx',
            reloadApache:
                'sudo apachectl configtest && sudo systemctl reload apache2',
            /** Shown snippet; switches to Apache when the server check finds it. */
            webServer: 'nginx' as 'apache' | 'nginx',
            ExternalLink: markRaw(ExternalLink),
            RefreshCw: markRaw(RefreshCw),
            Server: markRaw(Server),
            Zap: markRaw(Zap),
        };
    },

    computed: {
        webServerOptions(): ('apache' | 'nginx')[] {
            return ['apache', 'nginx'];
        },

        serverUrl(): string {
            return servers.show(this.project.server_id).url;
        },

        workersUrl(): string {
            return projects.workers.index(this.project.id).url;
        },

        setupSteps(): string[] {
            return [
                this.$t('Creates releases/ and shared/ in :path', {
                    path: this.project.deploy_path,
                }),
                this.$t(
                    'Copies .env and storage once from the folder you give',
                ),
                this.$t(
                    'Trusts the SSH host key of your git host (e.g. github.com)',
                ),
                this.$t('Checks that the server can read the repository'),
                this.$t('Gives shared/storage to the web user (:user)', {
                    user: this.project.web_user || '—',
                }),
                this.$t('Prints every step, so you see what changed'),
            ];
        },

        moveSteps(): {
            text: string;
            link?: { label: string; href: string };
        }[] {
            const id = this.project.id;

            return [
                {
                    text: this.$t(
                        'Run quick setup with “Import from” set to the folder of the site today (e.g. /var/www/my-site).',
                    ),
                },
                {
                    text: this.$t('Check the imported .env and save it.'),
                    link: {
                        label: this.$t('Environment'),
                        href: projects.environment.edit(id).url,
                    },
                },
                {
                    text: this.$t(
                        'Deploy: the first release is created and current points to it. The site still runs from the old folder.',
                    ),
                },
                this.webServer === 'apache'
                    ? {
                          text: this.$t(
                              'Change DocumentRoot to :path in the Apache site (both files: http and -le-ssl).',
                              { path: this.project.paths.public },
                          ),
                      }
                    : {
                          text: this.$t('Change the Nginx root to :path.', {
                              path: this.project.paths.public,
                          }),
                      },
                {
                    text:
                        this.webServer === 'apache'
                            ? this.$t(
                                  'Test and reload Apache: sudo apachectl configtest && sudo systemctl reload apache2.',
                              )
                            : this.$t(
                                  'Test and reload Nginx: sudo nginx -t && sudo systemctl reload nginx.',
                              ),
                },
            ];
        },
    },

    watch: {
        // The server check tells which web server is running: show its config.
        checks: {
            immediate: true,
            handler(result: ChecksResult | undefined) {
                const server = result?.checks?.web_server;

                if (server === 'apache' || server === 'nginx') {
                    this.webServer = server;
                }
            },
        },
    },

    methods: {
        runSetup(): void {
            this.form.submit(projects.setup.run(this.project.id), {
                preserveScroll: true,
            });
        },

        checkAgain(): void {
            router.reload({
                only: ['checks'],
                onStart: () => (this.checking = true),
                onFinish: () => (this.checking = false),
            });
        },
    },
});
</script>
