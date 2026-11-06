<template>
    <div>
        <!-- Header -->
        <div
            class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="min-w-0">
                <Link
                    :href="projectsUrl"
                    class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-brand-600"
                >
                    <ArrowLeft class="size-4" />
                    {{ $t('Projects') }}
                </Link>
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-violet-600 text-base font-semibold text-white shadow-sm shadow-brand-600/30"
                    >
                        {{ project.name.charAt(0).toUpperCase() }}
                    </span>
                    <h1
                        class="truncate text-2xl font-semibold tracking-tight text-slate-900"
                    >
                        {{ project.name }}
                    </h1>
                    <StatusBadge
                        v-if="project.latest_deployment"
                        :status="project.latest_deployment.status"
                        :label="
                            $t('Last deploy: :status', {
                                status: project.latest_deployment.status_label,
                            })
                        "
                    />
                </div>
                <div
                    class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-slate-500"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <GitBranch class="size-4 text-slate-400" />
                        {{ project.branch }}
                    </span>
                    <Link
                        v-if="project.server"
                        :href="serverUrl"
                        class="inline-flex items-center gap-1.5 hover:text-brand-600"
                    >
                        <Server class="size-4 text-slate-400" />
                        {{ project.server.name }}
                    </Link>
                    <a
                        v-if="project.github_slug"
                        :href="`https://github.com/${project.github_slug}`"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 hover:text-brand-600"
                    >
                        <FolderGit2 class="size-4 text-slate-400" />
                        {{ project.github_slug }}
                    </a>
                    <span
                        v-else
                        class="inline-flex max-w-xs items-center gap-1.5 truncate"
                    >
                        <FolderGit2 class="size-4 shrink-0 text-slate-400" />
                        <span class="truncate">{{ project.repository }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <Hammer class="size-4 text-slate-400" />
                        {{ project.build_strategy_label }}
                    </span>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <AppButton
                    v-if="project.url"
                    :href="project.url"
                    external
                    variant="secondary"
                    :icon="ExternalLink"
                    >{{ $t('Open site') }}</AppButton
                >
                <AppButton
                    :icon="Rocket"
                    :disabled="!!activeDeployment"
                    @click="deployOpen = true"
                    >{{ $t('Deploy') }}</AppButton
                >
            </div>
        </div>

        <!-- A deployment is queued / running -->
        <Link
            v-if="activeDeployment"
            :href="deploymentUrl(activeDeployment.id)"
            class="mb-5 flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 transition hover:bg-brand-100/70"
        >
            <LoaderCircle class="size-5 shrink-0 animate-spin text-brand-600" />
            <span class="min-w-0 flex-1">
                <span class="font-semibold">{{
                    activeDeployment.status === 'pending'
                        ? $t('Deployment #:id is queued', {
                              id: activeDeployment.id,
                          })
                        : $t('Deployment #:id is running', {
                              id: activeDeployment.id,
                          })
                }}</span>
                <span class="hidden text-brand-700/80 sm:inline">
                    · {{ $t('open the live log') }}</span
                >
            </span>
            <ChevronRight class="size-4 shrink-0" />
        </Link>

        <!-- Tabs -->
        <nav
            class="-mx-4 mb-6 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                class="relative flex shrink-0 items-center gap-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition"
                :class="
                    tab.key === current
                        ? 'text-brand-700'
                        : 'text-slate-500 hover:text-slate-900'
                "
            >
                <component :is="tab.icon" class="size-4" />
                {{ $t(tab.label) }}
                <span
                    v-if="tab.key === current"
                    class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-600"
                />
            </Link>
        </nav>

        <slot />

        <DeployModal
            :show="deployOpen"
            :project="project"
            :options="deployOptions"
            @close="deployOpen = false"
        />
    </div>
</template>

<script lang="ts">
import {
    ArrowLeft,
    Boxes,
    ChevronRight,
    Cog,
    ExternalLink,
    FolderGit2,
    GitBranch,
    Hammer,
    History,
    KeyRound,
    LoaderCircle,
    Rocket,
    Server,
    Terminal,
    Workflow,
    Zap,
} from '@lucide/vue';
import { Link, router } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import DeployModal from '@/components/modules/projects/DeployModal.vue';
import deployments from '@/routes/deployments';
import projects from '@/routes/projects';
import servers from '@/routes/servers';
import type { Card, Build, Deployment, Project } from '@/types';

type ProjectTab = { key: string; label: string; href: string; icon: Component };

/**
 * Header, tabs and Deploy button shared by all the pages of a project.
 * While a deployment is queued / running it reloads "activeDeployment"
 * (and the props in reloadOnly) every few seconds.
 */
export default defineComponent({
    name: 'ProjectShell',

    components: {
        AppButton,
        ArrowLeft,
        ChevronRight,
        DeployModal,
        FolderGit2,
        GitBranch,
        Hammer,
        Link,
        LoaderCircle,
        Server,
        StatusBadge,
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
        /** Key of the current tab. */
        current: { type: String, required: true },
        /** Other props of the page to refresh while a deployment runs. */
        reloadOnly: { type: Array as PropType<string[]>, default: () => [] },
    },

    data() {
        return {
            deployOpen: false,
            timer: null as ReturnType<typeof setTimeout> | null,
            ExternalLink: markRaw(ExternalLink),
            Rocket: markRaw(Rocket),
        };
    },

    computed: {
        tabs(): ProjectTab[] {
            const id = this.project.id;

            return [
                {
                    key: 'deployments',
                    label: 'Deployments',
                    href: projects.show(id).url,
                    icon: markRaw(Rocket),
                },
                {
                    key: 'releases',
                    label: 'Releases',
                    href: projects.releases.index(id).url,
                    icon: markRaw(History),
                },
                {
                    key: 'builds',
                    label: 'Builds',
                    href: projects.builds.index(id).url,
                    icon: markRaw(Boxes),
                },
                {
                    key: 'hooks',
                    label: 'Deploy script',
                    href: projects.hooks.index(id).url,
                    icon: markRaw(Terminal),
                },
                {
                    key: 'workers',
                    label: 'Workers',
                    href: projects.workers.index(id).url,
                    icon: markRaw(Workflow),
                },
                {
                    key: 'environment',
                    label: 'Environment',
                    href: projects.environment.edit(id).url,
                    icon: markRaw(KeyRound),
                },
                {
                    key: 'setup',
                    label: 'Quick setup',
                    href: projects.setup.show(id).url,
                    icon: markRaw(Zap),
                },
                {
                    key: 'settings',
                    label: 'Settings',
                    href: projects.edit(id).url,
                    icon: markRaw(Cog),
                },
            ];
        },

        projectsUrl(): string {
            return projects.index().url;
        },

        serverUrl(): string {
            return servers.show(this.project.server_id).url;
        },
    },

    watch: {
        activeDeployment: {
            immediate: true,
            handler(active: Deployment | null, previous: Deployment | null) {
                if (active) {
                    this.schedule();
                } else if (previous) {
                    // It just finished: refresh the whole page once.
                    router.reload({ preserveScroll: true } as never);
                }
            },
        },
    },

    beforeUnmount() {
        if (this.timer) {
            clearTimeout(this.timer);
        }
    },

    methods: {
        deploymentUrl(id: number): string {
            return deployments.show(id).url;
        },

        schedule(): void {
            if (this.timer) {
                clearTimeout(this.timer);
            }

            this.timer = setTimeout(() => {
                router.reload({
                    only: ['activeDeployment', 'project', ...this.reloadOnly],
                    preserveScroll: true,
                    onFinish: () => {
                        if (this.activeDeployment) {
                            this.schedule();
                        }
                    },
                } as never);
            }, 3000);
        },
    },
});
</script>
