<template>
    <AppModal
        :show="show"
        :title="
            stage
                ? $t('Add a command to “:stage”', { stage: stage.label })
                : $t('Add a command')
        "
        :description="stage?.description ?? ''"
        size="lg"
        @close="$emit('close')"
    >
        <div class="space-y-6">
            <section v-for="group in groups" :key="group.key">
                <h3
                    class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase"
                >
                    {{ group.title }}
                </h3>
                <div class="grid grid-cols-1 gap-2.5 md:grid-cols-2">
                    <button
                        v-for="preset in group.presets"
                        :key="preset.key"
                        type="button"
                        class="group flex min-w-0 flex-col rounded-lg border border-slate-200 p-3 text-left transition hover:border-brand-400 hover:bg-brand-50/40 hover:ring-4 hover:ring-brand-50 disabled:cursor-wait disabled:opacity-60"
                        :disabled="processing"
                        @click="$emit('choose', preset)"
                    >
                        <span class="flex items-start justify-between gap-2">
                            <span
                                class="text-sm font-semibold text-slate-900 group-hover:text-brand-700"
                                >{{ preset.name }}</span
                            >
                            <span class="flex shrink-0 items-center gap-1">
                                <span
                                    v-if="isAdded(preset)"
                                    class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-100"
                                    >{{ $t('Added') }}</span
                                >
                                <span
                                    v-if="stage && preset.stage !== stage.value"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500"
                                    :title="$t('Usually runs in this stage')"
                                    >{{ stageLabel(preset.stage) }}</span
                                >
                            </span>
                        </span>
                        <span
                            class="mt-0.5 text-xs leading-relaxed text-slate-500"
                            >{{ preset.description }}</span
                        >
                        <code
                            class="mt-2 line-clamp-3 rounded-md bg-slate-950 px-2.5 py-1.5 font-mono text-[11.5px] leading-relaxed break-all whitespace-pre-wrap text-slate-300"
                            >{{ preset.script.trim() }}</code
                        >
                    </button>
                </div>
            </section>

            <!-- Write your own -->
            <button
                v-if="custom"
                type="button"
                class="group flex w-full items-center gap-3 rounded-lg border-2 border-dashed border-slate-300 p-4 text-left transition hover:border-brand-400 hover:bg-brand-50/40 disabled:cursor-wait disabled:opacity-60"
                :disabled="processing"
                @click="$emit('choose', custom)"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 group-hover:bg-brand-600 group-hover:text-white"
                >
                    <SquareTerminal class="size-5" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-slate-900">{{
                        $t('Custom command')
                    }}</span>
                    <span class="block text-xs text-slate-500">{{
                        $t(
                            'Start from an empty bash script: artisan, npm, anything that runs on the server.',
                        )
                    }}</span>
                </span>
                <Plus
                    class="ml-auto size-5 shrink-0 text-slate-400 group-hover:text-brand-600"
                />
            </button>
        </div>

        <template #footer>
            <AppButton variant="secondary" @click="$emit('close')">{{
                $t('Cancel')
            }}</AppButton>
        </template>
    </AppModal>
</template>

<script lang="ts">
import { Plus, SquareTerminal } from '@lucide/vue';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppModal from '@/components/modals/AppModal.vue';
import type { Card, HookPreset } from '@/types';

type PresetGroup = { key: string; title: string; presets: HookPreset[] };

/** Picks a ready-made deploy command (presets of the stage first) or an empty custom one. */
export default defineComponent({
    name: 'HookPresetModal',

    components: { AppButton, AppModal, Plus, SquareTerminal },

    props: {
        show: { type: Boolean, default: false },
        /** The stage the command is added to. */
        stage: { type: Object as PropType<Card | null>, default: null },
        stages: { type: Array as PropType<Card[]>, required: true },
        presets: { type: Array as PropType<HookPreset[]>, required: true },
        /** Names of the commands the project already has. */
        existing: { type: Array as PropType<string[]>, default: () => [] },
        processing: { type: Boolean, default: false },
    },

    emits: ['choose', 'close'],

    computed: {
        custom(): HookPreset | null {
            return (
                this.presets.find((preset) => preset.key === 'custom') ?? null
            );
        },

        groups(): PresetGroup[] {
            const ready = this.presets.filter(
                (preset) => preset.key !== 'custom',
            );
            const stage = this.stage?.value;
            const groups: PresetGroup[] = [
                {
                    key: 'stage',
                    title: this.$t('Made for this stage'),
                    presets: ready.filter((preset) => preset.stage === stage),
                },
                {
                    key: 'other',
                    title: this.$t('Other ready-made commands'),
                    presets: ready.filter((preset) => preset.stage !== stage),
                },
            ];

            return groups.filter((group) => group.presets.length > 0);
        },
    },

    methods: {
        isAdded(preset: HookPreset): boolean {
            return this.existing.includes(preset.name);
        },

        stageLabel(value: string): string {
            return (
                this.stages.find((stage) => stage.value === value)?.label ??
                value
            );
        },
    },
});
</script>
