<template>
    <div
        class="overflow-hidden rounded-lg border bg-white shadow-xs transition"
        :class="[
            form.enabled
                ? 'border-slate-200'
                : 'border-dashed border-slate-300 bg-slate-50/60',
            { 'ring-2 ring-amber-200': form.isDirty },
        ]"
    >
        <!-- Name, on/off, order, delete -->
        <div
            class="flex flex-wrap items-center gap-x-2 gap-y-2 border-b border-slate-100 px-3 py-2"
        >
            <span
                class="flex size-6 shrink-0 items-center justify-center rounded-md bg-violet-50 text-[11px] font-semibold text-violet-600 ring-1 ring-violet-100"
                >{{ position }}</span
            >
            <input
                v-model="form.name"
                type="text"
                maxlength="100"
                class="min-w-0 flex-1 basis-40 rounded-md border border-transparent bg-transparent px-2 py-1 text-sm font-semibold text-slate-900 transition outline-none hover:border-slate-200 focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-100"
                :class="{
                    'text-slate-400 line-through decoration-slate-300':
                        !form.enabled,
                }"
                :aria-label="$t('Name')"
                @keydown.enter.prevent="save"
            />

            <div class="ml-auto flex shrink-0 items-center gap-1">
                <button
                    type="button"
                    role="switch"
                    :aria-checked="form.enabled"
                    class="mr-1 inline-flex items-center gap-2 rounded-md px-1.5 py-1 text-xs font-medium transition hover:bg-slate-100"
                    :class="
                        form.enabled ? 'text-emerald-700' : 'text-slate-500'
                    "
                    :title="form.enabled ? $t('Turn off') : $t('Turn on')"
                    @click="toggle"
                >
                    <span
                        class="relative inline-flex h-5 w-9 shrink-0 rounded-full transition-colors"
                        :class="
                            form.enabled ? 'bg-emerald-500' : 'bg-slate-300'
                        "
                    >
                        <span
                            class="absolute top-0.5 left-0.5 size-4 rounded-full bg-white shadow transition-transform"
                            :class="
                                form.enabled ? 'translate-x-4' : 'translate-x-0'
                            "
                        />
                    </span>
                    <span class="hidden sm:inline">{{
                        form.enabled ? $t('On') : $t('Off')
                    }}</span>
                </button>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-30"
                    :disabled="first || moving"
                    :title="$t('Move up')"
                    @click="$emit('move', -1)"
                >
                    <ArrowUp class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-30"
                    :disabled="last || moving"
                    :title="$t('Move down')"
                    @click="$emit('move', 1)"
                >
                    <ArrowDown class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                    :title="$t('Delete')"
                    @click="$emit('delete', hook)"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>
        </div>

        <!-- Script -->
        <div class="p-3">
            <textarea
                v-model="form.script"
                :rows="rows"
                spellcheck="false"
                autocapitalize="off"
                autocomplete="off"
                class="code-editor"
                :class="{ 'opacity-60': !form.enabled }"
                :aria-label="$t('Script')"
                @keydown.ctrl.s.prevent="save"
                @keydown.meta.s.prevent="save"
            />
            <p v-if="form.errors.name" class="form-error">
                {{ form.errors.name }}
            </p>
            <p v-if="form.errors.script" class="form-error">
                {{ form.errors.script }}
            </p>

            <div
                class="mt-2.5 flex flex-wrap items-center justify-between gap-2"
            >
                <label
                    class="flex min-w-0 items-center gap-2 text-xs text-slate-500"
                >
                    <span class="shrink-0">{{ $t('Runs') }}</span>
                    <select
                        v-model="form.stage"
                        class="min-w-0 rounded-md border border-slate-200 bg-white py-1 pr-7 pl-2 text-xs text-slate-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                    >
                        <option
                            v-for="stage in stages"
                            :key="stage.value"
                            :value="stage.value"
                        >
                            {{ stage.label }}
                        </option>
                    </select>
                </label>

                <div class="flex items-center gap-2">
                    <span
                        v-if="form.isDirty"
                        class="text-xs font-medium text-amber-600"
                        >{{ $t('Unsaved changes') }}</span
                    >
                    <span
                        v-else-if="form.recentlySuccessful"
                        class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600"
                    >
                        <Check class="size-3.5" />
                        {{ $t('Saved') }}
                    </span>
                    <AppButton
                        v-if="form.isDirty"
                        variant="ghost"
                        size="sm"
                        :disabled="form.processing"
                        @click="discard"
                        >{{ $t('Discard') }}</AppButton
                    >
                    <AppButton
                        size="sm"
                        :variant="form.isDirty ? 'primary' : 'secondary'"
                        :icon="Save"
                        :loading="form.processing"
                        :disabled="!form.isDirty"
                        @click="save"
                        >{{ $t('Save') }}</AppButton
                    >
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts">
import { ArrowDown, ArrowUp, Check, Save, Trash2 } from '@lucide/vue';
import { useForm } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import projects from '@/routes/projects';
import type { Card, Hook, HookStage } from '@/types';

/** One custom deploy command: name, on/off, bash script, stage; saved with PUT. */
export default defineComponent({
    name: 'HookCard',

    components: { AppButton, ArrowDown, ArrowUp, Check, Trash2 },

    props: {
        hook: { type: Object as PropType<Hook>, required: true },
        projectId: { type: Number, required: true },
        stages: { type: Array as PropType<Card[]>, required: true },
        /** 1-based position inside its stage. */
        position: { type: Number, required: true },
        first: { type: Boolean, default: false },
        last: { type: Boolean, default: false },
        /** A reorder request is on its way. */
        moving: { type: Boolean, default: false },
    },

    emits: ['move', 'delete'],

    data() {
        return {
            Save: markRaw(Save),
            form: useForm({
                name: this.hook.name,
                stage: this.hook.stage as HookStage,
                script: this.hook.script,
                enabled: this.hook.enabled,
            }),
        };
    },

    computed: {
        /** Grows with the script, between 3 and 22 lines. */
        rows(): number {
            const lines = this.form.script.split('\n').length;

            return Math.min(22, Math.max(3, lines + 1));
        },
    },

    watch: {
        /** New data from the server (after a save, a reorder…): take it unless the user is editing. */
        hook: {
            deep: true,
            handler(hook: Hook) {
                if (this.form.isDirty || this.form.processing) {
                    return;
                }

                this.form.defaults({
                    name: hook.name,
                    stage: hook.stage,
                    script: hook.script,
                    enabled: hook.enabled,
                });
                this.form.reset();
            },
        },
    },

    methods: {
        save(): void {
            if (this.form.isDirty) {
                this.submit();
            }
        },

        submit(): void {
            if (this.form.processing) {
                return;
            }

            // On success useForm takes the sent values as the new defaults (not dirty any more).
            this.form.put(
                projects.hooks.update([this.projectId, this.hook.id]).url,
                { preserveScroll: true },
            );
        },

        /** On / off is saved right away (with any other pending change of this command). */
        toggle(): void {
            this.form.enabled = !this.form.enabled;
            this.submit();
        },

        discard(): void {
            this.form.reset();
            this.form.clearErrors();
        },
    },
});
</script>
