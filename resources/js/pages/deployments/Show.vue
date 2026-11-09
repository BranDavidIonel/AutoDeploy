<template>
    <Head :title="title" />

    <!-- Header -->
    <div
        class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
    >
        <div class="min-w-0">
            <Link
                v-if="deployment.project"
                :href="projectUrl"
                class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-brand-600"
            >
                <ArrowLeft class="size-4" />
                {{ deployment.project.name }}
            </Link>
            <div class="flex flex-wrap items-center gap-3">
                <h1
                    class="text-2xl font-semibold tracking-tight text-slate-900"
                >
                    {{ title }}
                </h1>
                <StatusBadge
                    :status="deployment.status"
                    :label="deployment.status_label"
                />
                <StatusBadge v-if="deployment.is_live" status="live" />
            </div>
            <p class="mt-1 text-sm text-slate-500">
                {{ server.name }} · {{ server.address }}
                <template v-if="deployment.note">
                    · {{ deployment.note }}</template
                >
            </p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <AppButton
                v-if="deployment.project?.url && !deployment.is_active"
                :href="deployment.project.url"
                external
                variant="secondary"
                :icon="ExternalLink"
                >{{ $t('Open site') }}</AppButton
            >
            <AppButton
                v-if="deployment.is_active"
                variant="danger"
                :icon="Square"
                :loading="cancelling"
                @click="cancel"
                >{{
                    deployment.cancel_requested
                        ? $t('Force stop')
                        : $t('Cancel')
                }}</AppButton
            >
            <AppButton
                v-else-if="deployment.type === 'deploy'"
                variant="secondary"
                :icon="RotateCw"
                :loading="redeploying"
                @click="redeploy"
                >{{ $t('Deploy again') }}</AppButton
            >
        </div>
    </div>

    <!-- Waiting for the queue worker (QueueWorkerMonitor::status() says why) -->
    <div
        v-if="waitingForWorker && deployment.status === 'pending'"
        class="mb-6 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
    >
        <LoaderCircle
            v-if="queueWorker === 'starting'"
            class="mt-0.5 size-5 shrink-0 animate-spin text-amber-600"
        />
        <TriangleAlert v-else class="mt-0.5 size-5 shrink-0 text-amber-600" />
        <div>
            <p class="font-semibold">
                {{ workerNotice.title }}
            </p>
            <p class="mt-0.5 text-amber-800">
                {{ workerNotice.text }}
                <code
                    v-if="workerNotice.manual"
                    class="code-inline bg-amber-100"
                    >php artisan queue:work --timeout=3600</code
                >
            </p>
        </div>
    </div>

    <!-- Error -->
    <div
        v-if="deployment.error && !deployment.is_active"
        class="mb-6 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm"
        :class="
            deployment.status === 'cancelled'
                ? 'border-amber-200 bg-amber-50 text-amber-900'
                : 'border-rose-200 bg-rose-50 text-rose-900'
        "
    >
        <CircleX
            class="mt-0.5 size-5 shrink-0"
            :class="
                deployment.status === 'cancelled'
                    ? 'text-amber-600'
                    : 'text-rose-600'
            "
        />
        <div class="min-w-0">
            <p class="font-semibold">
                {{
                    deployment.status === 'cancelled'
                        ? $t('The deployment was cancelled')
                        : $t('The deployment failed')
                }}
            </p>
            <p class="mt-0.5 break-words">{{ deployment.error }}</p>
            <p
                v-if="deployment.status === 'failed' && !deployment.is_live"
                class="mt-1.5 text-rose-800/80"
            >
                {{
                    $t(
                        'The site was not touched: it still runs the previous release.',
                    )
                }}
            </p>
            <Link
                v-else-if="deployment.is_live"
                :href="releasesUrl"
                class="mt-1.5 inline-flex items-center gap-1 font-medium text-rose-700 underline-offset-2 hover:underline"
                >{{ $t('The new release is live: roll back from Releases') }}
                <ChevronRight class="size-4"
            /></Link>
        </div>
    </div>

    <!-- Summary -->
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card px-4 py-3.5">
            <p class="text-xs font-medium text-slate-500">{{ $t('Commit') }}</p>
            <p
                class="mt-1 flex items-center gap-1.5 font-mono text-sm font-semibold text-slate-900"
            >
                <GitCommitHorizontal class="size-4 text-slate-400" />
                <a
                    v-if="commitUrl"
                    :href="commitUrl"
                    target="_blank"
                    rel="noopener"
                    class="hover:text-brand-600"
                    >{{ deployment.short_commit }}</a
                >
                <span v-else>{{ deployment.short_commit ?? '—' }}</span>
            </p>
            <p
                v-if="deployment.commit_message"
                class="mt-0.5 truncate text-xs text-slate-500"
                :title="deployment.commit_message"
            >
                {{ deployment.commit_message }}
            </p>
        </div>
        <div class="card px-4 py-3.5">
            <p class="text-xs font-medium text-slate-500">
                {{ $t('Branch / assets') }}
            </p>
            <p
                class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-slate-900"
            >
                <GitBranch class="size-4 text-slate-400" />
                {{ deployment.branch ?? '—' }}
            </p>
            <p class="mt-0.5 truncate text-xs text-slate-500">
                {{ deployment.build_source_label ?? '—' }}
                <template v-if="deployment.build_id">
                    · #{{ deployment.build_id }}</template
                >
            </p>
        </div>
        <div class="card px-4 py-3.5">
            <p class="text-xs font-medium text-slate-500">
                {{ $t('Release') }}
            </p>
            <p
                class="mt-1 flex items-center gap-1.5 font-mono text-sm font-semibold text-slate-900"
            >
                <FolderClock class="size-4 text-slate-400" />
                {{ deployment.release ?? '—' }}
            </p>
            <p class="mt-0.5 truncate text-xs text-slate-500">
                {{
                    deployment.type === 'rollback'
                        ? $t('Rollback')
                        : deployment.triggered_by === 'webhook'
                          ? $t('Started by a git push')
                          : $t('Started by :name', {
                                name: deployment.user ?? '—',
                            })
                }}
            </p>
        </div>
        <div class="card px-4 py-3.5">
            <p class="text-xs font-medium text-slate-500">
                {{ $t('Duration') }}
            </p>
            <p
                class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-slate-900 tabular-nums"
            >
                <Timer class="size-4 text-slate-400" />
                {{ formatDuration(elapsed) }}
            </p>
            <p class="mt-0.5 truncate text-xs text-slate-500">
                {{
                    formatDateTime(
                        deployment.started_at ?? deployment.created_at,
                    )
                }}
            </p>
        </div>
    </div>

    <!-- Steps -->
    <section class="card overflow-hidden">
        <header
            class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-3.5"
        >
            <div class="flex items-center gap-3">
                <h2 class="text-base font-semibold text-slate-900">
                    {{ $t('Steps') }}
                </h2>
                <span class="text-sm text-slate-500 tabular-nums"
                    >{{ doneSteps }} / {{ steps.length }}</span
                >
            </div>
            <button
                type="button"
                class="text-sm font-medium text-brand-600 hover:text-brand-700"
                @click="toggleAll"
            >
                {{ allOpen ? $t('Collapse all') : $t('Expand all') }}
            </button>
        </header>

        <!-- Progress bar -->
        <div class="h-1 bg-slate-100">
            <div
                class="h-full transition-all duration-500"
                :class="progressClass"
                :style="{ width: `${progress}%` }"
            />
        </div>

        <ol class="divide-y divide-slate-100">
            <li v-for="(step, index) in steps" :key="step.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-slate-50/80"
                    :class="{ 'bg-brand-50/40': step.status === 'running' }"
                    @click="toggle(step.id)"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full ring-1"
                        :class="stepIconClass(step.status)"
                    >
                        <LoaderCircle
                            v-if="step.status === 'running'"
                            class="size-4 animate-spin"
                        />
                        <Check
                            v-else-if="step.status === 'finished'"
                            class="size-4"
                        />
                        <X
                            v-else-if="step.status === 'failed'"
                            class="size-4"
                        />
                        <Minus
                            v-else-if="step.status === 'skipped'"
                            class="size-4"
                        />
                        <span v-else class="text-xs font-semibold">{{
                            index + 1
                        }}</span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="flex items-center gap-2 text-sm font-medium"
                            :class="
                                step.status === 'skipped' ||
                                step.status === 'pending'
                                    ? 'text-slate-400'
                                    : 'text-slate-900'
                            "
                        >
                            <span class="truncate">{{ step.name }}</span>
                            <span
                                v-if="step.is_hook"
                                class="shrink-0 rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-violet-600 uppercase ring-1 ring-violet-100"
                                >{{ $t('command') }}</span
                            >
                        </span>
                    </span>
                    <span
                        v-if="step.duration !== null"
                        class="shrink-0 text-xs text-slate-500 tabular-nums"
                        >{{ formatDuration(step.duration) }}</span
                    >
                    <ChevronDown
                        class="size-4 shrink-0 text-slate-400 transition"
                        :class="{ 'rotate-180': isOpen(step) }"
                    />
                </button>
                <div v-if="isOpen(step)" class="px-5 pb-4">
                    <LogOutput
                        :text="step.output"
                        :live="step.status === 'running'"
                        :empty="
                            step.status === 'pending'
                                ? $t('Not started yet.')
                                : $t('No output.')
                        "
                        :title="step.name"
                    />
                </div>
            </li>
        </ol>
    </section>
</template>

<script lang="ts">
import {
    ArrowLeft,
    Check,
    ChevronDown,
    ChevronRight,
    CircleX,
    ExternalLink,
    FolderClock,
    GitBranch,
    GitCommitHorizontal,
    LoaderCircle,
    Minus,
    RotateCw,
    Square,
    Timer,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import LogOutput from '@/components/general/LogOutput.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import deployments from '@/routes/deployments';
import projects from '@/routes/projects';
import type {
    Deployment,
    DeploymentStep,
    QueueWorkerStatus,
    StepStatus,
} from '@/types';
import { formatDateTime, formatDuration } from '@/utils/format';

/** One deployment: summary and the steps with their live log (reloads every 1.5 s while running). */
export default defineComponent({
    name: 'DeploymentShow',

    components: {
        AppButton,
        ArrowLeft,
        Check,
        ChevronDown,
        ChevronRight,
        CircleX,
        FolderClock,
        GitBranch,
        GitCommitHorizontal,
        Head,
        Link,
        LoaderCircle,
        LogOutput,
        Minus,
        StatusBadge,
        Timer,
        TriangleAlert,
        X,
    },

    props: {
        deployment: { type: Object as PropType<Deployment>, required: true },
        server: {
            type: Object as PropType<{ name: string; address: string }>,
            required: true,
        },
        waitingForWorker: { type: Boolean, default: false },
        /** Sent while the deployment waits: running, starting, failed, off or stopped. */
        queueWorker: {
            type: String as PropType<QueueWorkerStatus | null>,
            default: null,
        },
    },

    data() {
        return {
            /** Steps the user opened or closed by hand (id → open). */
            manual: {} as Record<number, boolean>,
            allOpen: false,
            now: Date.now(),
            ticker: null as ReturnType<typeof setInterval> | null,
            poller: null as ReturnType<typeof setTimeout> | null,
            cancelling: false,
            redeploying: false,
            ExternalLink: markRaw(ExternalLink),
            RotateCw: markRaw(RotateCw),
            Square: markRaw(Square),
        };
    },

    computed: {
        /** Why the deployment still waits in the queue, and whether to show the manual command. */
        workerNotice(): { title: string; text: string; manual: boolean } {
            switch (this.queueWorker) {
                case 'starting':
                    return {
                        title: this.$t('Starting the queue worker…'),
                        text: this.$t(
                            'No worker was running, so AutoDeploy started one in the background. The deployment begins in a few seconds.',
                        ),
                        manual: false,
                    };
                case 'running':
                    return {
                        title: this.$t('Waiting for the queue worker'),
                        text: this.$t(
                            'A queue worker is running but has not picked up this deployment yet. It may be busy with another deployment.',
                        ),
                        manual: false,
                    };
                case 'failed':
                    return {
                        title: this.$t('The queue worker did not start'),
                        text: this.$t(
                            'AutoDeploy tried to start it in the background (see storage/logs/queue-worker.log). Start it in a terminal in the AutoDeploy folder:',
                        ),
                        manual: true,
                    };
                case 'off':
                    return {
                        title: this.$t('Waiting for the queue worker'),
                        text: this.$t(
                            'The automatic start is off (AUTODEPLOY_AUTO_WORKER=false). Start the worker in a terminal in the AutoDeploy folder:',
                        ),
                        manual: true,
                    };
                default:
                    return {
                        title: this.$t('Waiting for the queue worker'),
                        text: this.$t(
                            'Deployments run in the background. Start the worker in a terminal in the AutoDeploy folder:',
                        ),
                        manual: true,
                    };
            }
        },

        title(): string {
            return this.deployment.type === 'rollback'
                ? this.$t('Rollback #:id', { id: this.deployment.id })
                : this.$t('Deployment #:id', { id: this.deployment.id });
        },

        steps(): DeploymentStep[] {
            return this.deployment.steps ?? [];
        },

        doneSteps(): number {
            return this.steps.filter((s) => s.status === 'finished').length;
        },

        progress(): number {
            if (!this.steps.length) {
                return 0;
            }

            if (!this.deployment.is_active) {
                return 100;
            }

            const running = this.steps.some((s) => s.status === 'running')
                ? 0.5
                : 0;

            return Math.round(
                ((this.doneSteps + running) / this.steps.length) * 100,
            );
        },

        progressClass(): string {
            return {
                finished: 'bg-emerald-500',
                failed: 'bg-rose-500',
                cancelled: 'bg-amber-500',
                pending: 'bg-slate-300',
                running: 'bg-brand-500',
            }[this.deployment.status];
        },

        elapsed(): number | null {
            if (!this.deployment.started_at) {
                return null;
            }

            const end = this.deployment.finished_at
                ? new Date(this.deployment.finished_at).getTime()
                : this.now;

            return Math.max(
                0,
                Math.round(
                    (end - new Date(this.deployment.started_at).getTime()) /
                        1000,
                ),
            );
        },

        projectUrl(): string {
            return projects.show(this.deployment.project_id).url;
        },

        releasesUrl(): string {
            return projects.releases.index(this.deployment.project_id).url;
        },

        commitUrl(): string | null {
            const slug = this.deployment.project?.github_slug;

            return slug && this.deployment.commit_hash
                ? `https://github.com/${slug}/commit/${this.deployment.commit_hash}`
                : null;
        },
    },

    watch: {
        'deployment.is_active': {
            immediate: true,
            handler(active: boolean) {
                if (active) {
                    this.startPolling();
                } else {
                    this.stopPolling();
                }
            },
        },
    },

    beforeUnmount() {
        this.stopPolling();
    },

    methods: {
        formatDateTime,
        formatDuration,

        /** Running and failed steps open by themselves; the user can override. */
        isOpen(step: DeploymentStep): boolean {
            if (step.id in this.manual) {
                return this.manual[step.id];
            }

            return (
                this.allOpen ||
                step.status === 'running' ||
                step.status === 'failed'
            );
        },

        toggle(id: number): void {
            const step = this.steps.find((s) => s.id === id);

            if (step) {
                this.manual = { ...this.manual, [id]: !this.isOpen(step) };
            }
        },

        toggleAll(): void {
            this.allOpen = !this.allOpen;
            this.manual = {};
        },

        stepIconClass(status: StepStatus): string {
            return {
                pending: 'bg-white text-slate-400 ring-slate-200',
                running: 'bg-brand-50 text-brand-600 ring-brand-200',
                finished: 'bg-emerald-50 text-emerald-600 ring-emerald-200',
                failed: 'bg-rose-50 text-rose-600 ring-rose-200',
                skipped: 'bg-slate-50 text-slate-300 ring-slate-100',
            }[status];
        },

        startPolling(): void {
            if (!this.ticker) {
                this.ticker = setInterval(() => (this.now = Date.now()), 1000);
            }

            this.poll();
        },

        poll(): void {
            if (this.poller) {
                clearTimeout(this.poller);
            }

            this.poller = setTimeout(() => {
                router.reload({
                    only: [
                        'deployment',
                        'waitingForWorker',
                        'queueWorker',
                        'queueStuck',
                    ],
                    preserveScroll: true,
                    onFinish: () => {
                        if (this.deployment.is_active) {
                            this.poll();
                        }
                    },
                } as never);
            }, 1500);
        },

        stopPolling(): void {
            if (this.ticker) {
                clearInterval(this.ticker);
                this.ticker = null;
            }

            if (this.poller) {
                clearTimeout(this.poller);
                this.poller = null;
            }
        },

        cancel(): void {
            router.post(
                deployments.cancel(this.deployment.id).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => (this.cancelling = true),
                    onFinish: () => (this.cancelling = false),
                },
            );
        },

        redeploy(): void {
            router.post(
                deployments.redeploy(this.deployment.id).url,
                {},
                {
                    onStart: () => (this.redeploying = true),
                    onFinish: () => (this.redeploying = false),
                },
            );
        },
    },
});
</script>
