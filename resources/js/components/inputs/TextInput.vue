<template>
    <div>
        <label v-if="label" :for="inputId" class="form-label">
            {{ label }}
            <span v-if="required" class="text-rose-500">*</span>
        </label>
        <div class="relative">
            <span
                v-if="prefix"
                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm text-slate-400"
                >{{ prefix }}</span
            >
            <input
                :id="inputId"
                :type="type"
                :value="modelValue ?? ''"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :step="step"
                :min="min"
                :max="max"
                :autocomplete="autocomplete"
                class="form-control"
                :class="[
                    {
                        'form-control-error': error,
                        'pl-9': prefix,
                        'pr-12': suffix,
                    },
                    inputClass,
                ]"
                @input="onInput"
            />
            <span
                v-if="suffix"
                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-sm text-slate-400"
                >{{ suffix }}</span
            >
        </div>
        <p v-if="error" class="form-error">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>

<script lang="ts">
import type { PropType } from 'vue';
import { defineComponent } from 'vue';

let uid = 0;

export default defineComponent({
    name: 'TextInput',

    props: {
        modelValue: {
            type: [String, Number] as PropType<string | number | null>,
            default: null,
        },
        label: { type: String, default: '' },
        type: { type: String, default: 'text' },
        placeholder: { type: String, default: '' },
        error: { type: String, default: '' },
        hint: { type: String, default: '' },
        prefix: { type: String, default: '' },
        suffix: { type: String, default: '' },
        step: { type: [String, Number], default: undefined },
        min: { type: [String, Number], default: undefined },
        max: { type: [String, Number], default: undefined },
        autocomplete: { type: String, default: 'off' },
        inputClass: { type: String, default: '' },
        required: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            inputId: `input-${++uid}`,
        };
    },

    methods: {
        onInput(event: Event): void {
            const value = (event.target as HTMLInputElement).value;

            // Number inputs emit numbers (or null when empty), like v-model.number.
            if (this.type === 'number') {
                this.$emit(
                    'update:modelValue',
                    value === '' ? null : Number(value),
                );

                return;
            }

            this.$emit('update:modelValue', value);
        },
    },
});
</script>
