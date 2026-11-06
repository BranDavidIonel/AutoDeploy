<template>
    <AppModal
        :show="show"
        :title="$t('Deploy :name', { name: project.name })"
        :description="
            $t(
                'A new release is created next to the live one and switched in only when every step worked.',
            )
        "
        size="lg"
        @close="$emit('close')"
    >
        <form id="deploy-form" class="space-y-6" @submit.prevent="submit">
            <!-- Build source -->
            <div>
                <span class="form-label">{{
                    $t('Front-end assets (npm run build)')
                }}</span>
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    <button
                        v-for="strategy in options.strategies"
                        :key="strategy.value"
                        type="button"
                        class="relative flex gap-3 rounded-lg border p-3 text-left transition"
                        :class="
                            form.build_source === strategy.value
                                ? 'border-brand-500 bg-brand-50/60 ring-4 ring-brand-100'
                                : 'border-slate-200 hover:border-slate-300'
                        "
                        @click="pick(strategy.value)"
                    >
                        <span
                            class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md"
                            :class="
                                form.build_source === strategy.value
                                    ? 'bg-brand-600 text-white'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            <component
                                :is="strategyIcon(strategy.value)"
                                class="size-4"
                            />
                        </span>
                        <span class="min-w-0">
                            <span
                                class="flex items-center gap-2 text-sm font-semibold text-slate-900"
                            >
                                {{ strategy.label }}
                                <span
                                    v-if="
                                        strategy.value ===
                                        project.build_strategy
                                    "
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-slate-500 uppercase"
                                    >{{ $t('default') }}</span
                                >
                            </span>
                            <span
                                class="mt-0.5 block text-xs leading-relaxed text-slate-500"
                                >{{ strategy.description }}</span
                            >
                        </span>
                    </button>
                </div>

                <!-- Warnings for the chosen option -->
                <p
                    v-if="
                        form.build_source === 'local' &&
                        !project.local_path_exists
                    "
                    class="mt-3 flex items-start gap-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{
                        $t(
                            'The local project folder is not set or not found on this computer. Set it in Settings.',
                        )
                    }}
                </p>
                <p
                    v-if="form.build_source === 'server' && lowMemory"
                    class="mt-3 flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{
                        $t(
                            'The server has only :ram of RAM. npm run build may get killed; building locally is safer.',
                            { ram: serverRam },
                        )
                    }}
                </p>
            </div>

            <!-- Saved build to deploy -->
            <div v-if="form.build_source === 'upload'">
                <div class="mb-1.5 flex items-center justify-between gap-3">
                    <span class="form-label mb-0">{{ $t('Saved build') }}</span>
                    <Link
                        :href="buildsUrl"
                        class="text-sm font-medium text-brand-600 hover:text-brand-700"
                        >{{ $t('Upload a new build') }}</Link
                    >
                </div>
                <div
                    v-if="options.builds.length"
                    class="max-h-56 space-y-1.5 overflow-y-auto"
                >
                    <label
                        v-for="build in options.builds"
                        :key="build.id"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 transition"
                        :class="
                            form.build_id === build.id
                                ? 'border-brand-500 bg-brand-50/60'
                                : 'border-slate-200 hover:border-slate-300'
                        "
                    >
                        <input
                            v-model="form.build_id"
                            type="radio"
                            :value="build.id"
                            class="accent-brand-600"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-sm font-medium text-slate-900"
                                >#{{ build.id }} ·
                                {{ build.display_name }}</span
                            >
                            <span class="block text-xs text-slate-500">
                                {{ timeAgo(build.created_at) }} ·
                                {{ formatBytes(build.size) }} ·
                                {{
                                    $t(':count files', {
                                        count: build.file_count,
                                    })
                                }}
                                <template v-if="build.short_commit">
                                    · {{ build.short_commit }}</template
                                >
                            </span>
                        </span>
                        <TriangleAlert
                            v-if="!build.has_manifest"
                            class="size-4 text-amber-500"
                            :title="$t('No manifest.json in this build')"
                        />
                    </label>
                </div>
                <p
                    v-else
                    class="rounded-lg border border-dashed border-slate-300 px-4 py-5 text-center text-sm text-slate-500"
                >
                    {{
                        $t(
                            'No saved builds yet. Upload a .zip of your public/build folder in the Builds tab.',
                        )
                    }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput
                    v-model="form.branch"
                    :label="$t('Branch')"
                    :hint="
                        form.build_source === 'local'
                            ? $t(
                                  'With a local build, the commit of your local folder is deployed.',
                              )
                            : ''
                    "
                    :error="form.errors.branch"
                />
                <TextInput
                    v-model="form.note"
                    :label="$t('Note (optional)')"
                    :placeholder="$t('e.g. fix invoice totals')"
                    :error="form.errors.note"
                />
            </div>
        </form>

        <template #footer>
            <AppButton variant="secondary" @click="$emit('close')">{{
                $t('Cancel')
            }}</AppButton>
            <AppButton
                type="submit"
                form="deploy-form"
                :icon="Rocket"
                :loading="form.processing"
                :disabled="!canDeploy"
                >{{ $t('Start deploy') }}</AppButton
            >
        </template>
    </AppModal>
</template>

<script lang="ts">
import { Ban, Cloud, Laptop, Rocket, TriangleAlert, Upload } from '@lucide/vue';
import { Link, useForm } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import AppModal from '@/components/modals/AppModal.vue';
import projects from '@/routes/projects';
import type { Build, BuildStrategy, Card, Project } from '@/types';
import { formatBytes, formatMegabytes, timeAgo } from '@/utils/format';

const ICONS: Record<string, Component> = {
    local: markRaw(Laptop),
    upload: markRaw(Upload),
    server: markRaw(Cloud),
    none: markRaw(Ban),
};

/** Deploy options: where the assets come from, which saved build, branch, note. */
export default defineComponent({
    name: 'DeployModal',

    components: { AppButton, AppModal, Link, TextInput, TriangleAlert },

    props: {
        show: { type: Boolean, default: false },
        project: { type: Object as PropType<Project>, required: true },
        options: {
            type: Object as PropType<{ strategies: Card[]; builds: Build[] }>,
            required: true,
        },
    },

    emits: ['close'],

    data() {
        return {
            Rocket: markRaw(Rocket),
            form: useForm({
                build_source: this.project.build_strategy as BuildStrategy,
                build_id: (this.options.builds[0]?.id ?? null) as number | null,
                branch: this.project.branch,
                note: '',
            }),
        };
    },

    computed: {
        buildsUrl(): string {
            return projects.builds.index(this.project.id).url;
        },

        serverRam(): string {
            return formatMegabytes(this.project.server?.memory_mb ?? null);
        },

        lowMemory(): boolean {
            const ram = this.project.server?.memory_mb;

            return !!ram && ram < 2000;
        },

        canDeploy(): boolean {
            if (this.form.build_source === 'upload') {
                return this.form.build_id !== null;
            }

            if (this.form.build_source === 'local') {
                return this.project.local_path_exists;
            }

            return true;
        },
    },

    watch: {
        show(open: boolean) {
            if (open) {
                this.form.clearErrors();
                this.form.build_source = this.project.build_strategy;
                this.form.build_id = this.options.builds[0]?.id ?? null;
                this.form.branch = this.project.branch;
            }
        },
    },

    methods: {
        timeAgo,
        formatBytes,

        pick(value: string): void {
            this.form.build_source = value as BuildStrategy;
        },

        strategyIcon(value: string): Component {
            return ICONS[value] ?? ICONS.none;
        },

        submit(): void {
            this.form
                .transform((data) => ({
                    ...data,
                    build_id:
                        data.build_source === 'upload' ? data.build_id : null,
                }))
                .submit(projects.deploy(this.project.id), {
                    preserveScroll: true,
                    onSuccess: () => this.$emit('close'),
                });
        },
    },
});
</script>
