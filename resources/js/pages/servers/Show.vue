<template>
    <Head :title="server.name" />

    <!-- Header -->
    <div
        class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
    >
        <div class="min-w-0">
            <Link
                :href="indexUrl"
                class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-brand-600"
            >
                <ArrowLeft class="size-4" />
                {{ $t('Servers') }}
            </Link>
            <div class="flex flex-wrap items-center gap-3">
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-slate-700 to-slate-900 text-white shadow-sm shadow-slate-900/20"
                >
                    <Laptop v-if="server.is_local" class="size-5" />
                    <ServerIcon v-else class="size-5" />
                </span>
                <h1
                    class="truncate text-2xl font-semibold tracking-tight text-slate-900"
                >
                    {{ server.name }}
                </h1>
                <StatusBadge
                    :status="server.status"
                    :label="
                        server.status === 'failed'
                            ? $t('Connection failed')
                            : ''
                    "
                />
            </div>
            <div
                class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-slate-500"
            >
                <span
                    class="inline-flex items-center gap-1.5 font-mono text-[13px]"
                >
                    <Terminal class="size-4 text-slate-400" />
                    {{ server.address }}
                </span>
                <span
                    v-if="!server.is_local"
                    class="inline-flex items-center gap-1.5"
                >
                    <KeyRound class="size-4 text-slate-400" />
                    {{ authLabel }}
                </span>
                <span
                    class="inline-flex items-center gap-1.5"
                    :title="formatDateTime(server.last_checked_at)"
                >
                    <Clock class="size-4 text-slate-400" />
                    {{
                        server.last_checked_at
                            ? $t('checked :when', {
                                  when: timeAgo(server.last_checked_at),
                              })
                            : $t('never checked')
                    }}
                </span>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <AppButton :icon="PlugZap" :loading="testing" @click="test">{{
                $t('Test connection')
            }}</AppButton>
            <AppButton :href="editUrl" variant="secondary" :icon="Pencil">{{
                $t('Edit')
            }}</AppButton>
            <!-- The wrapper shows the reason even while the button is disabled. -->
            <span
                class="inline-flex"
                :title="
                    projects.length
                        ? $t('Delete or move its projects first')
                        : $t('Delete server')
                "
            >
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white p-2.5 text-slate-500 shadow-xs transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-600 focus-visible:ring-4 focus-visible:ring-rose-100 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                    :aria-label="$t('Delete server')"
                    :disabled="projects.length > 0"
                    @click="deleting = true"
                >
                    <Trash2 class="size-[18px]" />
                </button>
            </span>
        </div>
    </div>

    <!-- Last connection failed -->
    <div
        v-if="server.status === 'failed' && server.last_error"
        class="mb-6 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
    >
        <CircleX class="mt-0.5 size-5 shrink-0 text-rose-600" />
        <div class="min-w-0">
            <p class="font-semibold">{{ $t('The last connection failed') }}</p>
            <p
                class="mt-1 font-mono text-xs break-words whitespace-pre-wrap text-rose-800"
            >
                {{ server.last_error }}
            </p>
            <p class="mt-2 text-rose-800/80">
                {{
                    $t(
                        'Check the host, user and key in Edit, then press Test connection again.',
                    )
                }}
            </p>
        </div>
    </div>

    <!-- Never checked -->
    <div
        v-else-if="!checked"
        class="mb-6 flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"
    >
        <Info class="mt-0.5 size-5 shrink-0 text-sky-600" />
        <p>
            {{
                $t(
                    'Not checked yet. Press Test connection to read RAM, disk and the installed tools.',
                )
            }}
        </p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <!-- Low RAM: build somewhere else -->
            <div
                v-if="lowMemory"
                class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600"
                >
                    <Lightbulb class="size-5" />
                </span>
                <div class="min-w-0 space-y-1.5">
                    <p class="font-semibold">
                        {{ $t('Build the front-end off the server') }}
                    </p>
                    <p class="text-amber-800">
                        {{
                            $t(
                                'This server has :ram of RAM. npm run build can run out of memory here, so for its projects choose “Build locally” or “Upload a saved build”.',
                                { ram: formatMegabytes(server.memory_mb) },
                            )
                        }}
                    </p>
                    <p v-if="noSwap" class="text-amber-800">
                        {{
                            $t(
                                'There is no swap either: adding 1–2 GB of swap makes Composer and builds much safer.',
                            )
                        }}
                    </p>
                    <p
                        v-if="buildingOnServer.length"
                        class="flex flex-wrap items-center gap-1.5 pt-1 text-amber-900"
                    >
                        <TriangleAlert class="size-4 text-amber-600" />
                        {{ $t('Still building on the server:') }}
                        <Link
                            v-for="project in buildingOnServer"
                            :key="project.id"
                            :href="projectSettingsUrl(project.id)"
                            class="rounded-md bg-amber-100 px-1.5 py-0.5 font-medium hover:bg-amber-200"
                            >{{ project.name }}</Link
                        >
                    </p>
                </div>
            </div>

            <ServerResources
                :info="server.info"
                :checked="checked"
                :checked-at="server.last_checked_at"
            />

            <ServerSoftware :info="server.info" :checked="checked" />

            <!-- Projects -->
            <AppCard
                :title="$t('Projects on this server')"
                :description="
                    projects.length
                        ? $t('1 project|:count projects', {
                              count: projects.length,
                          })
                        : ''
                "
                flush
            >
                <template #actions>
                    <AppButton
                        :href="newProjectUrl"
                        variant="secondary"
                        size="sm"
                        :icon="Plus"
                        >{{ $t('New project') }}</AppButton
                    >
                </template>
                <ul v-if="projects.length" class="divide-y divide-slate-100">
                    <li v-for="project in projects" :key="project.id">
                        <Link
                            :href="projectUrl(project.id)"
                            class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-slate-50/80"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-brand-500 to-violet-600 text-sm font-semibold text-white shadow-sm shadow-brand-600/30"
                            >
                                {{ project.name.charAt(0).toUpperCase() }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-semibold text-slate-900"
                                    >{{ project.name }}</span
                                >
                                <span
                                    class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-500"
                                >
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <GitBranch class="size-3" />
                                        {{ project.branch }}
                                    </span>
                                    <span class="truncate font-mono">{{
                                        project.deploy_path
                                    }}</span>
                                </span>
                            </span>
                            <span
                                class="flex shrink-0 flex-col items-end gap-1"
                            >
                                <StatusBadge
                                    :status="
                                        project.latest_deployment?.status ??
                                        'unknown'
                                    "
                                    :label="
                                        project.latest_deployment
                                            ?.status_label ?? $t('Not deployed')
                                    "
                                />
                                <span
                                    v-if="project.last_deployed_at"
                                    class="text-[11px] text-slate-400"
                                    >{{
                                        timeAgo(project.last_deployed_at)
                                    }}</span
                                >
                            </span>
                            <ChevronRight
                                class="hidden size-4 shrink-0 text-slate-300 sm:block"
                            />
                        </Link>
                    </li>
                </ul>
                <EmptyState
                    v-else
                    :icon="FolderGit2"
                    :title="$t('No projects on this server yet')"
                    :description="
                        $t(
                            'Create a project and pick this server to deploy it here.',
                        )
                    "
                />
            </AppCard>
        </div>

        <div class="space-y-6">
            <!-- System -->
            <AppCard :title="$t('System')">
                <dl class="space-y-3 text-sm">
                    <div
                        v-for="row in system"
                        :key="row.label"
                        class="flex items-start justify-between gap-4"
                    >
                        <dt class="shrink-0 text-slate-500">{{ row.label }}</dt>
                        <dd
                            class="min-w-0 text-right break-words text-slate-800"
                            :class="{ 'font-mono text-[13px]': row.mono }"
                        >
                            {{ row.value || '—' }}
                        </dd>
                    </div>
                </dl>
            </AppCard>

            <DeployKeyCard :server="server" />

            <HostKeyCard v-if="!server.is_local" :server="server" />
        </div>
    </div>

    <ConfirmModal
        :show="deleting"
        :title="$t('Delete :name?', { name: server.name })"
        :message="
            $t(
                'The server is removed from AutoDeploy, with its saved key or password. Nothing is changed on the server itself.',
            )
        "
        :confirm-label="$t('Delete server')"
        :processing="destroying"
        @close="deleting = false"
        @confirm="destroy"
    />
</template>

<script lang="ts">
import {
    ArrowLeft,
    ChevronRight,
    CircleX,
    Clock,
    FolderGit2,
    GitBranch,
    Info,
    KeyRound,
    Laptop,
    Lightbulb,
    Pencil,
    PlugZap,
    Plus,
    Server as ServerIcon,
    Terminal,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import DeployKeyCard from '@/components/modules/servers/DeployKeyCard.vue';
import HostKeyCard from '@/components/modules/servers/HostKeyCard.vue';
import ServerResources from '@/components/modules/servers/ServerResources.vue';
import ServerSoftware from '@/components/modules/servers/ServerSoftware.vue';
import { isLowMemory, toNumber } from '@/components/modules/servers/usage';
import projectRoutes from '@/routes/projects';
import serverRoutes from '@/routes/servers';
import type { Project, Server } from '@/types';
import { formatDateTime, formatMegabytes, timeAgo } from '@/utils/format';

/** One server: connection status, resources, installed tools, SSH keys and its projects. */
export default defineComponent({
    name: 'ServersShow',

    components: {
        AppButton,
        AppCard,
        ArrowLeft,
        ChevronRight,
        CircleX,
        Clock,
        ConfirmModal,
        DeployKeyCard,
        EmptyState,
        GitBranch,
        Head,
        HostKeyCard,
        Info,
        KeyRound,
        Laptop,
        Lightbulb,
        Link,
        ServerIcon,
        ServerResources,
        ServerSoftware,
        StatusBadge,
        Terminal,
        Trash2,
        TriangleAlert,
    },

    props: {
        server: { type: Object as PropType<Server>, required: true },
        projects: { type: Array as PropType<Project[]>, default: () => [] },
    },

    data() {
        return {
            testing: false,
            deleting: false,
            destroying: false,
            FolderGit2: markRaw(FolderGit2),
            Pencil: markRaw(Pencil),
            PlugZap: markRaw(PlugZap),
            Plus: markRaw(Plus),
        };
    },

    computed: {
        indexUrl(): string {
            return serverRoutes.index().url;
        },

        editUrl(): string {
            return serverRoutes.edit(this.server.id).url;
        },

        newProjectUrl(): string {
            return projectRoutes.create().url;
        },

        /** The server answered at least once (info was read). */
        checked(): boolean {
            return Object.keys(this.server.info ?? {}).length > 0;
        },

        lowMemory(): boolean {
            return isLowMemory(this.server.memory_mb);
        },

        noSwap(): boolean {
            return this.checked && !toNumber(this.server.info.swap_total);
        },

        buildingOnServer(): Project[] {
            return this.projects.filter((p) => p.build_strategy === 'server');
        },

        authLabel(): string {
            return {
                key_file: this.$t('Key file on this computer'),
                key: this.$t('Saved private key'),
                password: this.$t('Password'),
            }[this.server.auth_method];
        },

        system(): {
            label: string;
            value: string | undefined;
            mono?: boolean;
        }[] {
            const info = this.server.info;

            return [
                { label: this.$t('OS'), value: info.os },
                { label: this.$t('Kernel'), value: info.kernel, mono: true },
                {
                    label: this.$t('Hostname'),
                    value: info.hostname,
                    mono: true,
                },
                { label: this.$t('User'), value: info.user, mono: true },
                { label: this.$t('CPUs'), value: info.cpus },
                { label: this.$t('Uptime'), value: info.uptime },
                {
                    label: this.$t('Load average'),
                    value: info.load,
                    mono: true,
                },
            ];
        },
    },

    methods: {
        formatDateTime,
        formatMegabytes,
        timeAgo,

        projectUrl(id: number): string {
            return projectRoutes.show(id).url;
        },

        projectSettingsUrl(id: number): string {
            return projectRoutes.edit(id).url;
        },

        test(): void {
            router.post(
                serverRoutes.test(this.server.id).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => (this.testing = true),
                    onFinish: () => (this.testing = false),
                },
            );
        },

        destroy(): void {
            router.delete(serverRoutes.destroy(this.server.id).url, {
                onStart: () => (this.destroying = true),
                onFinish: () => (this.destroying = false),
                onSuccess: () => (this.deleting = false),
            });
        },
    },
});
</script>
