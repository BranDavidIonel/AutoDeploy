<template>
    <article class="card overflow-hidden">
        <!-- Name and state -->
        <header
            class="flex items-start gap-3 border-b border-slate-100 px-4 py-4 sm:px-5"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                :class="iconClass"
            >
                <Workflow class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="truncate text-base font-semibold text-slate-900">
                        {{ worker.name }}
                    </h3>
                    <StatusBadge :status="worker.status" />
                </div>
                <p class="mt-0.5 truncate text-xs text-slate-500">
                    <span class="font-mono">{{ worker.program_name }}</span>
                    <span
                        v-if="worker.checked_at"
                        :title="formatDateTime(worker.checked_at)"
                    >
                        ·
                        {{
                            $t('checked :when', {
                                when: timeAgo(worker.checked_at),
                            })
                        }}</span
                    >
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-0.5">
                <button
                    type="button"
                    class="rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                    :title="$t('Edit')"
                    @click="$emit('edit', worker)"
                >
                    <Pencil class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded-md p-2 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                    :title="$t('Delete')"
                    @click="$emit('delete', worker)"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>
        </header>

        <div class="space-y-4 px-4 py-4 sm:px-5">
            <!-- Command -->
            <div
                class="rounded-lg bg-slate-950 px-3.5 py-2.5 font-mono text-[12px] leading-relaxed break-all text-slate-200"
            >
                <span class="text-slate-500">$ </span>{{ worker.command }}
            </div>

            <!-- Settings -->
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                <div v-for="fact in facts" :key="fact.label" class="min-w-0">
                    <dt class="text-xs font-medium text-slate-500">
                        {{ fact.label }}
                    </dt>
                    <dd
                        class="mt-0.5 truncate text-sm font-medium text-slate-800"
                        :class="{ 'font-mono text-[13px]': fact.mono }"
                        :title="fact.value"
                    >
                        {{ fact.value }}
                    </dd>
                </div>
            </dl>

            <!-- Actions -->
            <div class="flex flex-wrap items-center gap-2">
                <AppButton
                    size="sm"
                    :variant="installed ? 'secondary' : 'primary'"
                    :icon="installed ? FileCog : Download"
                    :loading="busy === 'install'"
                    :disabled="busy !== null"
                    @click="run('install')"
                    >{{
                        installed ? $t('Apply config') : $t('Install and start')
                    }}</AppButton
                >
                <template v-if="installed">
                    <AppButton
                        v-if="!isRunning"
                        size="sm"
                        variant="success"
                        :icon="Play"
                        :loading="busy === 'start'"
                        :disabled="busy !== null"
                        @click="run('start')"
                        >{{ $t('Start') }}</AppButton
                    >
                    <AppButton
                        v-else
                        size="sm"
                        variant="secondary"
                        :icon="Square"
                        :loading="busy === 'stop'"
                        :disabled="busy !== null"
                        @click="run('stop')"
                        >{{ $t('Stop') }}</AppButton
                    >
                    <AppButton
                        size="sm"
                        variant="secondary"
                        :icon="RotateCw"
                        :loading="busy === 'restart'"
                        :disabled="busy !== null"
                        @click="run('restart')"
                        >{{ $t('Restart') }}</AppButton
                    >
                </template>
                <AppButton
                    size="sm"
                    variant="secondary"
                    :icon="ScrollText"
                    @click="$emit('logs', worker)"
                    >{{ $t('Logs') }}</AppButton
                >
                <AppButton
                    size="sm"
                    variant="ghost"
                    :icon="RefreshCw"
                    :loading="busy === 'status'"
                    :disabled="busy !== null"
                    @click="run('status')"
                    >{{ $t('Refresh status') }}</AppButton
                >
            </div>
        </div>

        <!-- Supervisor config -->
        <div class="border-t border-slate-100">
            <button
                type="button"
                class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium text-slate-600 transition hover:bg-slate-50 sm:px-5"
                :aria-expanded="open"
                @click="open = !open"
            >
                <FileCode class="size-4 text-slate-400" />
                {{ $t('Supervisor config') }}
                <span
                    class="hidden min-w-0 truncate font-mono text-xs font-normal text-slate-400 sm:inline"
                    >{{ worker.config_path }}</span
                >
                <ChevronDown
                    class="ml-auto size-4 shrink-0 text-slate-400 transition"
                    :class="{ 'rotate-180': open }"
                />
            </button>
            <div v-if="open" class="space-y-3 px-4 pb-4 sm:px-5">
                <LogOutput
                    :text="worker.config"
                    :title="worker.config_path"
                    max-height="max-h-80"
                />
                <LogOutput
                    v-if="worker.status_output"
                    :text="worker.status_output"
                    :title="$t('Last status from supervisorctl')"
                    max-height="max-h-48"
                />
            </div>
        </div>
    </article>
</template>

<script lang="ts">
import {
    ChevronDown,
    Download,
    FileCode,
    FileCog,
    Pencil,
    Play,
    RefreshCw,
    RotateCw,
    ScrollText,
    Square,
    Trash2,
    Workflow,
} from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import LogOutput from '@/components/general/LogOutput.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import projects from '@/routes/projects';
import type { Worker } from '@/types';
import { formatDateTime, timeAgo } from '@/utils/format';

type WorkerAction = 'install' | 'start' | 'stop' | 'restart' | 'status';

/** One Supervisor worker: state, command, settings, start / stop / restart, config. */
export default defineComponent({
    name: 'WorkerCard',

    components: {
        AppButton,
        ChevronDown,
        FileCode,
        LogOutput,
        Pencil,
        StatusBadge,
        Trash2,
        Workflow,
    },

    props: {
        worker: { type: Object as PropType<Worker>, required: true },
        projectId: { type: Number, required: true },
    },

    emits: ['edit', 'delete', 'logs'],

    data() {
        return {
            open: false,
            busy: null as WorkerAction | null,
            Download: markRaw(Download),
            FileCog: markRaw(FileCog),
            Play: markRaw(Play),
            RefreshCw: markRaw(RefreshCw),
            RotateCw: markRaw(RotateCw),
            ScrollText: markRaw(ScrollText),
            Square: markRaw(Square),
        };
    },

    computed: {
        installed(): boolean {
            return (
                !!this.worker.installed_at &&
                this.worker.status !== 'not_installed'
            );
        },

        isRunning(): boolean {
            return (
                this.worker.status === 'running' ||
                this.worker.status === 'starting'
            );
        },

        iconClass(): string {
            return {
                running: 'bg-emerald-50 text-emerald-600',
                starting: 'bg-sky-50 text-sky-600',
                stopped: 'bg-amber-50 text-amber-600',
                fatal: 'bg-rose-50 text-rose-600',
                not_installed: 'bg-slate-100 text-slate-400',
                unknown: 'bg-slate-100 text-slate-500',
            }[this.worker.status];
        },

        facts(): { label: string; value: string; mono?: boolean }[] {
            const w = this.worker;

            return [
                { label: this.$t('Processes'), value: String(w.processes) },
                {
                    label: this.$t('User'),
                    value: w.user || this.$t('SSH user'),
                    mono: !!w.user,
                },
                {
                    label: this.$t('Connection'),
                    value: w.connection,
                    mono: true,
                },
                { label: this.$t('Queues'), value: w.queue, mono: true },
                { label: this.$t('Timeout'), value: `${w.timeout}s` },
                { label: this.$t('Tries'), value: String(w.tries) },
                { label: this.$t('Max time'), value: `${w.max_time}s` },
                { label: this.$t('Memory'), value: `${w.memory} MB` },
            ];
        },
    },

    methods: {
        formatDateTime,
        timeAgo,

        run(action: WorkerAction): void {
            router.post(
                projects.workers.action({
                    project: this.projectId,
                    worker: this.worker.id,
                    action,
                }).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => (this.busy = action),
                    onFinish: () => (this.busy = null),
                },
            );
        },
    },
});
</script>
