<template>
    <Head :title="$t('Dashboard')" />

    <!-- Greeting -->
    <div
        class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="min-w-0">
            <p
                class="text-sm font-medium text-brand-600 first-letter:uppercase"
            >
                {{ today }}
            </p>
            <h1
                class="mt-1 truncate text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl"
            >
                {{ greeting }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $t('Here is how your projects and servers are doing.') }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <AppButton
                :href="serverCreateUrl"
                variant="secondary"
                :icon="ServerCog"
                >{{ $t('Add server') }}</AppButton
            >
            <AppButton :href="projectCreateUrl" :icon="Plus">{{
                $t('New project')
            }}</AppButton>
        </div>
    </div>

    <!-- Something is deploying right now -->
    <Link
        v-if="activeDeployment"
        :href="deploymentUrl(activeDeployment.id)"
        class="mb-6 flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 transition hover:bg-brand-100/70"
    >
        <LoaderCircle class="size-5 shrink-0 animate-spin text-brand-600" />
        <span class="min-w-0 flex-1">
            <span class="font-semibold">{{
                $t(':project is deploying', {
                    project:
                        activeDeployment.project?.name ??
                        '#' + activeDeployment.id,
                })
            }}</span>
            <span v-if="stats.running > 1" class="text-brand-700/80">
                ·
                {{
                    $t(':count running in total', { count: stats.running })
                }}</span
            >
            <span class="hidden text-brand-700/80 sm:inline">
                · {{ $t('open the live log') }}</span
            >
        </span>
        <ChevronRight class="size-4 shrink-0" />
    </Link>

    <!-- Key figures -->
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard
            :label="$t('Projects')"
            :value="stats.projects"
            :hint="$t(':count live', { count: liveProjects })"
            :icon="FolderGit2"
        />
        <StatCard
            :label="$t('Deployments')"
            :value="stats.deploymentsWeek"
            :hint="
                stats.running
                    ? $t(':count running now', { count: stats.running })
                    : $t('last 7 days')
            "
            :icon="Rocket"
            color="violet"
        />
        <StatCard
            :label="$t('Success rate')"
            :value="stats.successRate === null ? '—' : `${stats.successRate}%`"
            :hint="$t('last 30 days')"
            :icon="Gauge"
            :color="successColor"
        />
        <StatCard
            :label="$t('Servers')"
            :value="stats.servers"
            :hint="$t(':count connected', { count: connectedServers })"
            :icon="ServerIcon"
            color="sky"
        />
    </div>

    <!-- First run -->
    <GettingStarted
        v-if="stats.projects === 0"
        class="mb-6"
        :has-servers="stats.servers > 0"
        :has-projects="stats.projects > 0"
    />

    <!-- Projects -->
    <section v-if="projects.length" class="mb-6">
        <div class="mb-3 flex items-center justify-between gap-4">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $t('Projects') }}
            </h2>
            <Link
                :href="projectsUrl"
                class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                {{ $t('View all') }}
                <ChevronRight class="size-4" />
            </Link>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <ProjectCard
                v-for="project in projects"
                :key="project.id"
                :project="project"
            />
        </div>
    </section>

    <!-- Activity and servers -->
    <div v-if="stats.servers > 0" class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <RecentDeployments
            class="xl:col-span-2"
            :deployments="recentDeployments"
        />
        <ServersPanel :servers="servers" />
    </div>
</template>

<script lang="ts">
import {
    ChevronRight,
    FolderGit2,
    Gauge,
    LoaderCircle,
    Plus,
    Rocket,
    Server as ServerIcon,
    ServerCog,
} from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import StatCard from '@/components/general/StatCard.vue';
import GettingStarted from '@/components/modules/dashboard/GettingStarted.vue';
import RecentDeployments from '@/components/modules/dashboard/RecentDeployments.vue';
import ServersPanel from '@/components/modules/dashboard/ServersPanel.vue';
import ProjectCard from '@/components/modules/projects/ProjectCard.vue';
import { getLocale } from '@/i18n';
import deploymentRoutes from '@/routes/deployments';
import projectRoutes from '@/routes/projects';
import serverRoutes from '@/routes/servers';
import type { Deployment, Project, Server } from '@/types';

type Stats = {
    projects: number;
    servers: number;
    deploymentsWeek: number;
    successRate: number | null;
    running: number;
};

/** Home: greeting, key figures, projects, recent deployments and server health. */
export default defineComponent({
    name: 'Dashboard',

    components: {
        AppButton,
        ChevronRight,
        GettingStarted,
        Head,
        Link,
        LoaderCircle,
        ProjectCard,
        RecentDeployments,
        ServersPanel,
        StatCard,
    },

    props: {
        stats: { type: Object as PropType<Stats>, required: true },
        projects: { type: Array as PropType<Project[]>, required: true },
        servers: { type: Array as PropType<Server[]>, required: true },
        recentDeployments: {
            type: Array as PropType<Deployment[]>,
            required: true,
        },
    },

    data() {
        return {
            poller: null as ReturnType<typeof setTimeout> | null,
            stopped: false,
            FolderGit2: markRaw(FolderGit2),
            Gauge: markRaw(Gauge),
            Plus: markRaw(Plus),
            Rocket: markRaw(Rocket),
            ServerCog: markRaw(ServerCog),
            ServerIcon: markRaw(ServerIcon),
        };
    },

    computed: {
        firstName(): string {
            return this.$page.props.auth.user?.name.split(/\s+/)[0] ?? '';
        },

        greeting(): string {
            const hour = new Date().getHours();
            const name = this.firstName;

            if (hour < 12) {
                return name
                    ? this.$t('Good morning, :name', { name })
                    : this.$t('Good morning');
            }

            if (hour < 18) {
                return name
                    ? this.$t('Good afternoon, :name', { name })
                    : this.$t('Good afternoon');
            }

            return name
                ? this.$t('Good evening, :name', { name })
                : this.$t('Good evening');
        },

        today(): string {
            // Re-computed when the language changes.
            const locale = String(this.$page.props.locale ?? getLocale());

            return new Intl.DateTimeFormat(
                locale === 'ro' ? 'ro-RO' : 'en-GB',
                {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                },
            ).format(new Date());
        },

        liveProjects(): number {
            return this.projects.filter((p) => p.active_release).length;
        },

        connectedServers(): number {
            return this.servers.filter((s) => s.status === 'connected').length;
        },

        successColor(): 'emerald' | 'amber' | 'rose' {
            const rate = this.stats.successRate;

            if (rate === null || rate >= 90) {
                return 'emerald';
            }

            return rate >= 70 ? 'amber' : 'rose';
        },

        activeDeployment(): Deployment | null {
            return this.recentDeployments.find((d) => d.is_active) ?? null;
        },

        serverCreateUrl(): string {
            return serverRoutes.create().url;
        },

        projectCreateUrl(): string {
            return projectRoutes.create().url;
        },

        projectsUrl(): string {
            return projectRoutes.index().url;
        },
    },

    watch: {
        // While something deploys, keep the figures and statuses fresh.
        'stats.running': {
            immediate: true,
            handler(running: number) {
                if (running > 0) {
                    this.schedule();
                }
            },
        },
    },

    beforeUnmount() {
        this.stopped = true;

        if (this.poller) {
            clearTimeout(this.poller);
        }
    },

    methods: {
        deploymentUrl(id: number): string {
            return deploymentRoutes.show(id).url;
        },

        schedule(): void {
            if (this.stopped) {
                return;
            }

            if (this.poller) {
                clearTimeout(this.poller);
            }

            this.poller = setTimeout(() => {
                router.reload({
                    only: ['stats', 'projects', 'recentDeployments'],
                    preserveScroll: true,
                    onFinish: () => {
                        if (this.stats.running > 0) {
                            this.schedule();
                        }
                    },
                } as never);
            }, 5000);
        },
    },
});
</script>
