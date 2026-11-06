<template>
    <Head :title="project.name" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="deployments"
        :reload-only="['deployments', 'stats']"
    >
        <!-- Key figures -->
        <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                :label="$t('Live release')"
                :value="project.active_release ?? '—'"
                :hint="
                    project.last_deployed_at
                        ? $t('deployed :when', {
                              when: timeAgo(project.last_deployed_at),
                          })
                        : $t('never deployed')
                "
                :icon="CircleCheckBig"
                color="emerald"
            />
            <StatCard
                :label="$t('Deployments')"
                :value="stats.total"
                :hint="
                    $t(':count in the last 30 days', { count: stats.last30 })
                "
                :icon="Rocket"
            />
            <StatCard
                :label="$t('Success rate')"
                :value="
                    stats.successRate === null ? '—' : `${stats.successRate}%`
                "
                :hint="$t('last 30 days')"
                :icon="Gauge"
                color="sky"
            />
            <StatCard
                :label="$t('Average duration')"
                :value="formatDuration(stats.averageSeconds)"
                :hint="
                    $t(':count releases kept for rollback', {
                        count: stats.releasesOnServer,
                    })
                "
                :icon="Timer"
                color="violet"
            />
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- Deployment history -->
            <AppCard
                class="xl:col-span-2"
                :title="$t('Deployment history')"
                flush
            >
                <div v-if="deployments.data.length" class="overflow-x-auto">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>{{ $t('Status') }}</th>
                                <th>{{ $t('Commit') }}</th>
                                <th class="hidden md:table-cell">
                                    {{ $t('Assets') }}
                                </th>
                                <th class="hidden sm:table-cell">
                                    {{ $t('When') }}
                                </th>
                                <th class="text-right">
                                    {{ $t('Duration') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="deployment in deployments.data"
                                :key="deployment.id"
                                class="cursor-pointer"
                                @click="open(deployment.id)"
                            >
                                <td>
                                    <div
                                        class="flex flex-col items-start gap-1"
                                    >
                                        <StatusBadge
                                            :status="deployment.status"
                                            :label="deployment.status_label"
                                        />
                                        <StatusBadge
                                            v-if="deployment.is_live"
                                            status="live"
                                        />
                                    </div>
                                </td>
                                <td class="max-w-xs">
                                    <Link
                                        :href="deploymentUrl(deployment.id)"
                                        class="flex items-center gap-2 font-medium text-slate-900 hover:text-brand-600"
                                        @click.stop
                                    >
                                        <span class="text-slate-400"
                                            >#{{ deployment.id }}</span
                                        >
                                        <RotateCcw
                                            v-if="
                                                deployment.type === 'rollback'
                                            "
                                            class="size-3.5 text-amber-500"
                                        />
                                        <span class="truncate">{{
                                            deployment.commit_message ||
                                            deployment.note ||
                                            (deployment.type === 'rollback'
                                                ? $t('Rollback')
                                                : $t('Deploy'))
                                        }}</span>
                                    </Link>
                                    <p
                                        class="mt-0.5 flex items-center gap-2 text-xs text-slate-500"
                                    >
                                        <span
                                            v-if="deployment.short_commit"
                                            class="font-mono"
                                            >{{ deployment.short_commit }}</span
                                        >
                                        <span>{{ deployment.branch }}</span>
                                        <span
                                            v-if="
                                                deployment.triggered_by ===
                                                'webhook'
                                            "
                                            class="rounded bg-slate-100 px-1 text-[10px] font-medium uppercase"
                                            >push</span
                                        >
                                    </p>
                                </td>
                                <td
                                    class="hidden text-xs text-slate-500 md:table-cell"
                                >
                                    {{ deployment.build_source_label ?? '—' }}
                                </td>
                                <td
                                    class="hidden text-xs whitespace-nowrap text-slate-500 sm:table-cell"
                                >
                                    <span
                                        :title="
                                            formatDateTime(
                                                deployment.created_at,
                                            )
                                        "
                                        >{{
                                            timeAgo(deployment.created_at)
                                        }}</span
                                    >
                                    <span
                                        v-if="deployment.user"
                                        class="block"
                                        >{{ deployment.user }}</span
                                    >
                                </td>
                                <td
                                    class="text-right text-xs whitespace-nowrap text-slate-500 tabular-nums"
                                >
                                    {{ formatDuration(deployment.duration) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState
                    v-else
                    :icon="Rocket"
                    :title="$t('No deployments yet')"
                    :description="
                        $t(
                            'Run the quick setup once, save the .env, then press Deploy.',
                        )
                    "
                >
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <AppButton
                            :href="setupUrl"
                            variant="secondary"
                            :icon="Zap"
                            >{{ $t('Quick setup') }}</AppButton
                        >
                    </div>
                </EmptyState>
                <div
                    v-if="deployments.meta.last_page > 1"
                    class="border-t border-slate-100 px-4 py-3"
                >
                    <PaginationLinks :meta="deployments.meta" />
                </div>
            </AppCard>

            <!-- Project details -->
            <div class="space-y-6">
                <AppCard :title="$t('How it is deployed')">
                    <dl class="space-y-3 text-sm">
                        <div v-for="row in details" :key="row.label">
                            <dt class="text-xs font-medium text-slate-500">
                                {{ row.label }}
                            </dt>
                            <dd
                                class="mt-0.5 break-all text-slate-800"
                                :class="{ 'font-mono text-[13px]': row.mono }"
                            >
                                {{ row.value }}
                            </dd>
                        </div>
                    </dl>
                </AppCard>

                <AppCard :title="$t('Shortcuts')">
                    <div class="grid min-w-0 grid-cols-1 gap-2">
                        <Link
                            v-for="link in shortcuts"
                            :key="link.label"
                            :href="link.href"
                            class="group flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 px-3 py-2.5 text-sm transition hover:border-brand-300 hover:bg-brand-50/40"
                        >
                            <span
                                class="flex size-8 items-center justify-center rounded-md bg-slate-100 text-slate-500 group-hover:bg-brand-100 group-hover:text-brand-700"
                            >
                                <component :is="link.icon" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block font-medium text-slate-900"
                                    >{{ link.label }}</span
                                >
                                <span
                                    class="block truncate text-xs text-slate-500"
                                    >{{ link.hint }}</span
                                >
                            </span>
                            <ChevronRight class="size-4 text-slate-300" />
                        </Link>
                    </div>
                </AppCard>
            </div>
        </div>
    </ProjectShell>
</template>

<script lang="ts">
import {
    Boxes,
    ChevronRight,
    CircleCheckBig,
    Gauge,
    KeyRound,
    Rocket,
    RotateCcw,
    Timer,
    Workflow,
    Zap,
} from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import PaginationLinks from '@/components/general/PaginationLinks.vue';
import StatCard from '@/components/general/StatCard.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import deployments from '@/routes/deployments';
import projects from '@/routes/projects';
import type { Build, Card, Deployment, Paginated, Project } from '@/types';
import { formatDateTime, formatDuration, timeAgo } from '@/utils/format';

type Stats = {
    total: number;
    last30: number;
    successRate: number | null;
    averageSeconds: number | null;
    releasesOnServer: number;
};

/** Project overview: key figures, deployment history, how it is deployed. */
export default defineComponent({
    name: 'ProjectsShow',

    components: {
        AppButton,
        AppCard,
        ChevronRight,
        EmptyState,
        Head,
        Link,
        PaginationLinks,
        ProjectShell,
        RotateCcw,
        StatCard,
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
        deployments: {
            type: Object as PropType<Paginated<Deployment>>,
            required: true,
        },
        stats: { type: Object as PropType<Stats>, required: true },
    },

    data() {
        return {
            CircleCheckBig: markRaw(CircleCheckBig),
            Gauge: markRaw(Gauge),
            Rocket: markRaw(Rocket),
            Timer: markRaw(Timer),
            Zap: markRaw(Zap),
        };
    },

    computed: {
        setupUrl(): string {
            return projects.setup.show(this.project.id).url;
        },

        details(): { label: string; value: string; mono?: boolean }[] {
            const p = this.project;

            return [
                {
                    label: this.$t('Server'),
                    value: p.server
                        ? `${p.server.name} · ${p.server.address}`
                        : '—',
                },
                {
                    label: this.$t('Deploy folder'),
                    value: p.deploy_path,
                    mono: true,
                },
                {
                    label: this.$t('Nginx root'),
                    value: p.paths.public,
                    mono: true,
                },
                { label: this.$t('Assets'), value: p.build_strategy_label },
                {
                    label: this.$t('Kept releases'),
                    value: this.$t(':count (for rollback)', {
                        count: p.keep_releases,
                    }),
                },
                {
                    label: this.$t('Deploy on git push'),
                    value: p.auto_deploy ? this.$t('On') : this.$t('Off'),
                },
            ];
        },

        shortcuts(): {
            label: string;
            hint: string;
            href: string;
            icon: Component;
        }[] {
            const id = this.project.id;

            return [
                {
                    label: this.$t('Upload a build'),
                    hint: this.$t('A .zip of public/build made on your PC'),
                    href: projects.builds.index(id).url,
                    icon: markRaw(Boxes),
                },
                {
                    label: this.$t('Edit .env'),
                    hint: this.project.paths.shared + '/.env',
                    href: projects.environment.edit(id).url,
                    icon: markRaw(KeyRound),
                },
                {
                    label: this.$t('Queue workers'),
                    hint: this.$t('Supervisor + scheduler, one click'),
                    href: projects.workers.index(id).url,
                    icon: markRaw(Workflow),
                },
                {
                    label: this.$t('Quick setup'),
                    hint: this.$t('Check and prepare the server'),
                    href: projects.setup.show(id).url,
                    icon: markRaw(Zap),
                },
            ];
        },
    },

    methods: {
        formatDateTime,
        formatDuration,
        timeAgo,

        deploymentUrl(id: number): string {
            return deployments.show(id).url;
        },

        open(id: number): void {
            router.visit(this.deploymentUrl(id));
        },
    },
});
</script>
