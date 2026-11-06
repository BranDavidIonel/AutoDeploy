<template>
    <AppModal
        :show="show"
        :title="
            worker
                ? $t('Edit worker :name', { name: worker.name })
                : $t('Add a queue worker')
        "
        :description="
            $t(
                'Supervisor keeps php artisan queue:work running and starts it again if it stops.',
            )
        "
        size="lg"
        @close="$emit('close')"
    >
        <form id="worker-form" class="space-y-5" @submit.prevent="submit">
            <TextInput
                v-model="form.name"
                :label="$t('Name')"
                :placeholder="$t('e.g. Default queue')"
                :error="form.errors.name"
                required
            />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput
                    v-model="form.connection"
                    :label="$t('Connection')"
                    placeholder="database"
                    input-class="font-mono"
                    :hint="$t('As QUEUE_CONNECTION in .env: database, redis…')"
                    :error="form.errors.connection"
                    required
                />
                <TextInput
                    v-model="form.queue"
                    :label="$t('Queues')"
                    placeholder="default"
                    input-class="font-mono"
                    :hint="
                        $t('Comma separated, by priority, e.g. high,default')
                    "
                    :error="form.errors.queue"
                    required
                />
                <TextInput
                    v-model="form.processes"
                    type="number"
                    min="1"
                    max="20"
                    :label="$t('Processes')"
                    :hint="$t('How many workers run in parallel.')"
                    :error="form.errors.processes"
                    required
                />
                <TextInput
                    v-model="form.user"
                    :label="$t('Run as user')"
                    placeholder="www-data"
                    input-class="font-mono"
                    :hint="$t('Usually the web user. Empty = the SSH user.')"
                    :error="form.errors.user"
                />
            </div>

            <!-- Limits -->
            <div>
                <p class="form-label">{{ $t('Limits') }}</p>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <TextInput
                        v-model="form.timeout"
                        type="number"
                        min="0"
                        :label="$t('Timeout')"
                        :suffix="$t('sec')"
                        :hint="$t('Longest a job may run.')"
                        :error="form.errors.timeout"
                    />
                    <TextInput
                        v-model="form.tries"
                        type="number"
                        min="0"
                        :label="$t('Tries')"
                        :hint="$t('Attempts before a job fails.')"
                        :error="form.errors.tries"
                    />
                    <TextInput
                        v-model="form.sleep"
                        type="number"
                        min="0"
                        :label="$t('Sleep')"
                        :suffix="$t('sec')"
                        :hint="$t('Wait when the queue is empty.')"
                        :error="form.errors.sleep"
                    />
                    <TextInput
                        v-model="form.max_time"
                        type="number"
                        min="0"
                        :label="$t('Max time')"
                        :suffix="$t('sec')"
                        :hint="$t('Restart the worker after this long.')"
                        :error="form.errors.max_time"
                    />
                    <TextInput
                        v-model="form.memory"
                        type="number"
                        min="32"
                        :label="$t('Memory')"
                        suffix="MB"
                        :hint="$t('Restart above this memory.')"
                        :error="form.errors.memory"
                    />
                </div>
            </div>

            <!-- What Supervisor will run -->
            <div>
                <p class="form-label">{{ $t('Command') }}</p>
                <div
                    class="rounded-lg bg-slate-950 px-3.5 py-2.5 font-mono text-[12px] leading-relaxed break-all text-slate-200"
                >
                    <span class="text-slate-500">$ </span>{{ preview }}
                </div>
            </div>

            <ToggleInput
                v-if="!installed"
                v-model="form.install"
                :label="$t('Apply on the server now')"
                :description="
                    $t(
                        'Writes the Supervisor config and starts the worker right after saving.',
                    )
                "
            />
            <p
                v-else
                class="flex items-start gap-2 rounded-md bg-brand-50/70 px-3 py-2 text-sm text-brand-800 ring-1 ring-brand-100"
            >
                <Info class="mt-0.5 size-4 shrink-0" />
                {{
                    $t(
                        'This worker is installed: saving writes the new config on the server and restarts it.',
                    )
                }}
            </p>
        </form>

        <template #footer>
            <AppButton variant="secondary" @click="$emit('close')">{{
                $t('Cancel')
            }}</AppButton>
            <AppButton
                type="submit"
                form="worker-form"
                :icon="Save"
                :loading="form.processing"
                >{{
                    installed || form.install
                        ? $t('Save and apply')
                        : $t('Save')
                }}</AppButton
            >
        </template>
    </AppModal>
</template>

<script lang="ts">
import { Info, Save } from '@lucide/vue';
import { useForm } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import ToggleInput from '@/components/inputs/ToggleInput.vue';
import AppModal from '@/components/modals/AppModal.vue';
import projects from '@/routes/projects';
import type { Project, Worker } from '@/types';

export type WorkerDefaults = {
    name: string;
    connection: string;
    queue: string;
    processes: number;
    timeout: number;
    sleep: number;
    tries: number;
    max_time: number;
    memory: number;
    user: string | null;
};

/** The editable fields of a worker (or of the defaults for a new one). */
function valuesOf(source: Worker | WorkerDefaults): WorkerDefaults {
    return {
        name: source.name,
        connection: source.connection,
        queue: source.queue,
        processes: source.processes,
        timeout: source.timeout,
        sleep: source.sleep,
        tries: source.tries,
        max_time: source.max_time,
        memory: source.memory,
        user: source.user,
    };
}

/** Add / edit a Supervisor queue worker. */
export default defineComponent({
    name: 'WorkerForm',

    components: { AppButton, AppModal, Info, TextInput, ToggleInput },

    props: {
        show: { type: Boolean, default: false },
        project: { type: Object as PropType<Project>, required: true },
        /** The worker to edit; null adds a new one. */
        worker: { type: Object as PropType<Worker | null>, default: null },
        defaults: { type: Object as PropType<WorkerDefaults>, required: true },
    },

    emits: ['close'],

    data() {
        return {
            Save: markRaw(Save),
            form: useForm({
                ...valuesOf(this.worker ?? this.defaults),
                install: true,
            }),
        };
    },

    computed: {
        installed(): boolean {
            return !!this.worker?.installed_at;
        },

        preview(): string {
            const f = this.form;

            return [
                `${this.project.php_binary} ${this.project.paths.current}/artisan queue:work ${f.connection || '…'}`,
                `--queue=${f.queue || '…'}`,
                `--sleep=${f.sleep ?? 0}`,
                `--tries=${f.tries ?? 0}`,
                `--timeout=${f.timeout ?? 0}`,
                `--max-time=${f.max_time ?? 0}`,
                `--memory=${f.memory ?? 0}`,
            ].join(' ');
        },
    },

    watch: {
        show(open: boolean) {
            if (open) {
                this.form.defaults({
                    ...valuesOf(this.worker ?? this.defaults),
                    install: !this.worker,
                });
                this.form.reset();
                this.form.clearErrors();
            }
        },
    },

    methods: {
        submit(): void {
            const options = {
                preserveScroll: true,
                onSuccess: () => this.$emit('close'),
            };

            if (this.worker) {
                this.form.put(
                    projects.workers.update([this.project.id, this.worker.id])
                        .url,
                    options,
                );
            } else {
                this.form.post(
                    projects.workers.store(this.project.id).url,
                    options,
                );
            }
        },
    },
});
</script>
