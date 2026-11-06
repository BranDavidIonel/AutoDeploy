<template>
    <AppCard :title="$t('Recent deployments')" flush>
        <ul v-if="deployments.length" class="divide-y divide-slate-100">
            <li v-for="deployment in deployments" :key="deployment.id">
                <Link
                    :href="deploymentUrl(deployment.id)"
                    class="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50/80"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full ring-1"
                        :class="iconClass(deployment.status)"
                        :title="deployment.status_label"
                    >
                        <component
                            :is="statusIcon(deployment.status)"
                            class="size-4"
                            :class="{
                                'animate-spin': deployment.status === 'running',
                            }"
                        />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2 text-sm">
                            <span
                                class="truncate font-semibold text-slate-900"
                                >{{ deployment.project?.name ?? '—' }}</span
                            >
                            <span class="shrink-0 text-xs text-slate-400"
                                >#{{ deployment.id }}</span
                            >
                            <RotateCcw
                                v-if="deployment.type === 'rollback'"
                                class="size-3.5 shrink-0 text-amber-500"
                                :title="$t('Rollback')"
                            />
                        </span>
                        <span
                            class="mt-0.5 block truncate text-xs text-slate-500"
                        >
                            {{
                                deployment.commit_message ||
                                deployment.note ||
                                (deployment.type === 'rollback'
                                    ? $t('Rollback')
                                    : $t('Deploy'))
                            }}
                        </span>
                        <span
                            class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-slate-400"
                        >
                            <span
                                v-if="deployment.short_commit"
                                class="font-mono text-slate-500"
                                >{{ deployment.short_commit }}</span
                            >
                            <span
                                v-if="deployment.branch"
                                class="inline-flex items-center gap-1"
                            >
                                <GitBranch class="size-3" />
                                {{ deployment.branch }}
                            </span>
                            <span
                                v-if="deployment.triggered_by === 'webhook'"
                                class="rounded bg-slate-100 px-1 text-[10px] font-medium text-slate-500 uppercase"
                                >push</span
                            >
                        </span>
                    </span>

                    <span class="shrink-0 text-right">
                        <StatusBadge
                            class="hidden sm:inline-flex"
                            :status="deployment.status"
                            :label="deployment.status_label"
                        />
                        <span
                            class="mt-1 block text-xs whitespace-nowrap text-slate-500"
                            :title="formatDateTime(deployment.created_at)"
                            >{{ timeAgo(deployment.created_at) }}</span
                        >
                        <span
                            v-if="deployment.duration !== null"
                            class="block text-[11px] text-slate-400 tabular-nums"
                            >{{ formatDuration(deployment.duration) }}</span
                        >
                    </span>
                </Link>
            </li>
        </ul>
        <EmptyState
            v-else
            :icon="Rocket"
            :title="$t('No deployments yet')"
            :description="
                $t(
                    'Open a project and press Deploy. The log of every run shows up here.',
                )
            "
        />
    </AppCard>
</template>

<script lang="ts">
import {
    Ban,
    Check,
    Clock,
    GitBranch,
    LoaderCircle,
    Rocket,
    RotateCcw,
    X,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppCard from '@/components/general/AppCard.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import deploymentRoutes from '@/routes/deployments';
import type { Deployment, DeploymentStatus } from '@/types';
import { formatDateTime, formatDuration, timeAgo } from '@/utils/format';

const ICONS: Record<DeploymentStatus, Component> = {
    pending: markRaw(Clock),
    running: markRaw(LoaderCircle),
    finished: markRaw(Check),
    failed: markRaw(X),
    cancelled: markRaw(Ban),
};

/** The last deployments of all projects, newest first. */
export default defineComponent({
    name: 'RecentDeployments',

    components: {
        AppCard,
        EmptyState,
        GitBranch,
        Link,
        RotateCcw,
        StatusBadge,
    },

    props: {
        deployments: { type: Array as PropType<Deployment[]>, required: true },
    },

    data() {
        return {
            Rocket: markRaw(Rocket),
        };
    },

    methods: {
        formatDateTime,
        formatDuration,
        timeAgo,

        deploymentUrl(id: number): string {
            return deploymentRoutes.show(id).url;
        },

        statusIcon(status: DeploymentStatus): Component {
            return ICONS[status] ?? ICONS.pending;
        },

        iconClass(status: DeploymentStatus): string {
            return {
                pending: 'bg-slate-50 text-slate-400 ring-slate-200',
                running: 'bg-brand-50 text-brand-600 ring-brand-200',
                finished: 'bg-emerald-50 text-emerald-600 ring-emerald-200',
                failed: 'bg-rose-50 text-rose-600 ring-rose-200',
                cancelled: 'bg-amber-50 text-amber-600 ring-amber-200',
            }[status];
        },
    },
});
</script>
