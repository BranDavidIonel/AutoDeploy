<template>
    <Head :title="`${$t('Workers')} · ${project.name}`" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="workers"
    >
        <!-- One-click setup -->
        <section
            class="relative mb-6 overflow-hidden rounded-2xl bg-linear-to-br from-brand-600 via-brand-600 to-violet-600 p-5 text-white shadow-lg shadow-brand-600/20 sm:p-7"
        >
            <div
                class="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
            />
            <div
                class="pointer-events-none absolute -bottom-20 left-1/3 size-56 rounded-full bg-violet-400/20 blur-3xl"
            />

            <div
                class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="max-w-2xl min-w-0">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-white/15 ring-1 ring-white/20"
                        >
                            <Zap class="size-5" />
                        </span>
                        <h2 class="text-lg font-semibold tracking-tight">
                            {{ $t('Quick setup for queue workers') }}
                        </h2>
                    </div>
                    <p class="mt-3 text-sm leading-relaxed text-white/85">
                        {{
                            $t(
                                'One click and the queue runs on the server, nothing to configure by hand:',
                            )
                        }}
                    </p>
                    <ul
                        class="mt-3 grid gap-2 text-sm text-white/90 sm:grid-cols-2"
                    >
                        <li
                            v-for="item in quickSetupSteps"
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

                <div
                    class="flex shrink-0 flex-col items-stretch gap-2 sm:items-start lg:items-end"
                >
                    <button
                        type="button"
                        class="shadow-brand-900/20 inline-flex items-center justify-center gap-2.5 rounded-xl bg-white px-6 py-3.5 text-base font-semibold text-brand-700 shadow-lg transition hover:bg-brand-50 focus-visible:ring-4 focus-visible:ring-white/40 focus-visible:outline-none disabled:cursor-wait disabled:opacity-80"
                        :disabled="settingUp"
                        @click="quickSetup"
                    >
                        <LoaderCircle
                            v-if="settingUp"
                            class="size-5 animate-spin"
                        />
                        <Zap v-else class="size-5" />
                        {{
                            settingUp
                                ? $t('Setting up…')
                                : workers.length
                                  ? $t('Run quick setup again')
                                  : $t('Set up queue workers')
                        }}
                    </button>
                    <p class="text-xs text-white/70 lg:text-right">
                        {{
                            settingUp
                                ? $t(
                                      'Installing Supervisor can take a minute or two.',
                                  )
                                : workersSummary
                        }}
                    </p>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Workers -->
            <section class="min-w-0 space-y-4 lg:col-span-2">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">
                            {{ $t('Queue workers') }}
                        </h2>
                        <p class="mt-0.5 text-sm text-slate-500">
                            {{
                                $t(
                                    'Supervisor keeps them running and starts them again after a crash or a reboot.',
                                )
                            }}
                        </p>
                    </div>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :icon="Plus"
                        @click="openForm(null)"
                        >{{ $t('Add worker') }}</AppButton
                    >
                </div>

                <WorkerCard
                    v-for="worker in workers"
                    :key="worker.id"
                    :worker="worker"
                    :project-id="project.id"
                    @edit="openForm"
                    @delete="toDelete = $event"
                    @logs="openLogs"
                />

                <div v-if="!workers.length" class="card">
                    <EmptyState
                        :icon="Workflow"
                        :title="$t('No queue workers yet')"
                        :description="
                            $t(
                                'Use Quick setup above, or add a worker by hand if you need special queues.',
                            )
                        "
                    />
                </div>
            </section>

            <!-- Scheduler and notes -->
            <aside class="min-w-0 space-y-6">
                <AppCard :title="$t('Laravel scheduler')">
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
                                'Scheduled tasks (routes/console.php) need one cron line that runs schedule:run every minute in the live release.',
                            )
                        }}
                    </p>

                    <div
                        class="mt-4 flex items-start gap-2 rounded-lg bg-slate-950 py-2 pr-1.5 pl-3.5"
                    >
                        <code
                            class="min-w-0 flex-1 py-0.5 font-mono text-[12px] leading-relaxed break-all text-slate-200"
                            >{{ cronLine }}</code
                        >
                        <CopyButton :text="cronLine" dark class="shrink-0" />
                    </div>

                    <AppButton
                        class="mt-4 w-full"
                        :variant="
                            project.scheduler_enabled ? 'secondary' : 'success'
                        "
                        :icon="
                            project.scheduler_enabled
                                ? CalendarX
                                : CalendarClock
                        "
                        :loading="schedulerBusy"
                        @click="toggleScheduler"
                        >{{
                            project.scheduler_enabled
                                ? $t('Disable scheduler')
                                : $t('Enable scheduler')
                        }}</AppButton
                    >
                    <p class="mt-2.5 text-xs leading-relaxed text-slate-500">
                        {{
                            project.web_user
                                ? $t(
                                      'Added to the crontab of :user when AutoDeploy connects as root, otherwise to the SSH user’s.',
                                      { user: project.web_user },
                                  )
                                : $t('Added to the crontab of the SSH user.')
                        }}
                    </p>
                </AppCard>

                <AppCard :title="$t('After every deploy')">
                    <div class="flex gap-3 text-sm text-slate-600">
                        <RotateCw
                            class="mt-0.5 size-4 shrink-0 text-violet-500"
                        />
                        <p class="leading-relaxed">
                            {{ $t('The command') }}
                            <code class="code-inline"
                                >php artisan queue:restart</code
                            >
                            {{
                                $t(
                                    'in the deploy script tells the workers to finish their job and start again with the new code.',
                                )
                            }}
                        </p>
                    </div>
                    <Link
                        :href="hooksUrl"
                        class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-700"
                    >
                        {{ $t('Open the deploy script') }}
                        <ChevronRight class="size-4" />
                    </Link>
                </AppCard>
            </aside>
        </div>

        <!-- Add / edit -->
        <WorkerForm
            :show="formOpen"
            :project="project"
            :worker="editing"
            :defaults="defaults"
            @close="formOpen = false"
        />

        <!-- Logs -->
        <AppModal
            :show="logs !== null"
            :title="logs ? $t('Logs · :name', { name: logs.worker.name }) : ''"
            :description="$t('The last 200 lines written by the worker.')"
            size="lg"
            @close="logs = null"
        >
            <div
                v-if="logs?.loading"
                class="flex h-64 items-center justify-center rounded-lg bg-slate-950 text-sm text-slate-400"
            >
                <LoaderCircle class="mr-2 size-4 animate-spin" />
                {{ $t('Reading the log on the server…') }}
            </div>
            <template v-else-if="logs">
                <p
                    v-if="!logs.ok"
                    class="mb-3 flex items-center gap-2 text-sm font-medium text-rose-700"
                >
                    <CircleX class="size-4" />
                    {{ $t('Could not read the log.') }}
                </p>
                <LogOutput
                    ref="log"
                    :text="logs.output"
                    :title="logs.worker.program_name + '.log'"
                    :empty="$t('The log is empty.')"
                    max-height="max-h-[55vh]"
                />
            </template>

            <template #footer>
                <AppButton
                    variant="secondary"
                    :icon="RefreshCw"
                    :loading="logs?.loading"
                    @click="logs && openLogs(logs.worker)"
                    >{{ $t('Refresh') }}</AppButton
                >
                <AppButton variant="secondary" @click="logs = null">{{
                    $t('Close')
                }}</AppButton>
            </template>
        </AppModal>

        <ConfirmModal
            :show="toDelete !== null"
            :title="$t('Remove this worker?')"
            :message="
                toDelete
                    ? $t(
                          '“:name” is stopped and its Supervisor config is deleted from the server.',
                          { name: toDelete.name },
                      )
                    : ''
            "
            :confirm-label="$t('Remove')"
            :processing="deleting"
            @confirm="destroy"
            @close="toDelete = null"
        />
    </ProjectShell>
</template>

<script lang="ts">
import {
    CalendarClock,
    CalendarX,
    ChevronRight,
    CircleCheck,
    CircleX,
    LoaderCircle,
    Plus,
    RefreshCw,
    RotateCw,
    Workflow,
    Zap,
} from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import CopyButton from '@/components/general/CopyButton.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import LogOutput from '@/components/general/LogOutput.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import AppModal from '@/components/modals/AppModal.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import WorkerCard from '@/components/modules/projects/WorkerCard.vue';
import WorkerForm from '@/components/modules/projects/WorkerForm.vue';
import type { WorkerDefaults } from '@/components/modules/projects/WorkerForm.vue';
import projects from '@/routes/projects';
import type { Build, Card, Deployment, Project, Worker } from '@/types';

type LogsState = {
    worker: Worker;
    loading: boolean;
    ok: boolean;
    output: string;
};

/** Queue workers (Supervisor) with a one-click setup, and the Laravel scheduler (cron). */
export default defineComponent({
    name: 'ProjectsWorkers',

    components: {
        AppButton,
        AppCard,
        AppModal,
        ChevronRight,
        CircleCheck,
        CircleX,
        ConfirmModal,
        CopyButton,
        EmptyState,
        Head,
        Link,
        LoaderCircle,
        LogOutput,
        ProjectShell,
        RotateCw,
        StatusBadge,
        WorkerCard,
        WorkerForm,
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
        workers: { type: Array as PropType<Worker[]>, required: true },
        cronLine: { type: String, required: true },
        defaults: { type: Object as PropType<WorkerDefaults>, required: true },
    },

    data() {
        return {
            settingUp: false,
            schedulerBusy: false,
            formOpen: false,
            editing: null as Worker | null,
            toDelete: null as Worker | null,
            deleting: false,
            logs: null as LogsState | null,
            CalendarClock: markRaw(CalendarClock),
            CalendarX: markRaw(CalendarX),
            Plus: markRaw(Plus),
            RefreshCw: markRaw(RefreshCw),
            Workflow: markRaw(Workflow),
        };
    },

    computed: {
        quickSetupSteps(): string[] {
            return [
                this.$t('Installs Supervisor if the server does not have it'),
                this.$t(
                    'Creates a “Default queue” worker: php artisan queue:work',
                ),
                this.$t('Runs it as the web user (:user) and starts it', {
                    user: this.project.web_user || this.$t('SSH user'),
                }),
                this.$t('queue:restart after every deploy loads the new code'),
            ];
        },

        workersSummary(): string {
            if (!this.workers.length) {
                return this.$t('Nothing is installed yet.');
            }

            const running = this.workers.filter(
                (w) => w.status === 'running',
            ).length;

            return this.$t(':running of :total workers running', {
                running,
                total: this.workers.length,
            });
        },

        hooksUrl(): string {
            return projects.hooks.index(this.project.id).url;
        },
    },

    methods: {
        quickSetup(): void {
            router.post(
                projects.workers.quickSetup(this.project.id).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => (this.settingUp = true),
                    onFinish: () => (this.settingUp = false),
                },
            );
        },

        toggleScheduler(): void {
            router.post(
                projects.workers.scheduler(this.project.id).url,
                { enabled: !this.project.scheduler_enabled },
                {
                    preserveScroll: true,
                    onStart: () => (this.schedulerBusy = true),
                    onFinish: () => (this.schedulerBusy = false),
                },
            );
        },

        openForm(worker: Worker | null): void {
            this.editing = worker;
            this.formOpen = true;
        },

        async openLogs(worker: Worker): Promise<void> {
            this.logs = { worker, loading: true, ok: true, output: '' };

            try {
                const response = await fetch(
                    projects.workers.logs([this.project.id, worker.id]).url,
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );

                if (!response.ok) {
                    throw new Error(
                        `HTTP ${response.status} ${response.statusText}`,
                    );
                }

                const data = (await response.json()) as {
                    ok: boolean;
                    output: string;
                };

                if (this.logs?.worker.id === worker.id) {
                    this.logs = {
                        worker,
                        loading: false,
                        ok: data.ok,
                        output: data.output,
                    };

                    // The newest lines are at the end.
                    this.$nextTick(() =>
                        (
                            this.$refs.log as
                                | InstanceType<typeof LogOutput>
                                | undefined
                        )?.scrollToBottom(),
                    );
                }
            } catch (error) {
                if (this.logs?.worker.id === worker.id) {
                    this.logs = {
                        worker,
                        loading: false,
                        ok: false,
                        output:
                            error instanceof Error
                                ? error.message
                                : String(error),
                    };
                }
            }
        },

        destroy(): void {
            if (!this.toDelete) {
                return;
            }

            router.delete(
                projects.workers.destroy([this.project.id, this.toDelete.id])
                    .url,
                {
                    preserveScroll: true,
                    onStart: () => (this.deleting = true),
                    onFinish: () => {
                        this.deleting = false;
                        this.toDelete = null;
                    },
                },
            );
        },
    },
});
</script>
