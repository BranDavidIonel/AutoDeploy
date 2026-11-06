<template>
    <div>
        <label v-if="label" :for="inputId" class="form-label">
            {{ label }}
            <span v-if="required" class="text-rose-500">*</span>
        </label>
        <select
            :id="inputId"
            :value="modelValue ?? ''"
            :required="required"
            :disabled="disabled"
            class="form-control pr-9"
            :class="{ 'form-control-error': error }"
            @change="onChange"
        >
            <option v-if="placeholder" value="">{{ placeholder }}</option>
            <option
                v-for="option in normalizedOptions"
                :key="String(option.value)"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <p v-if="error" class="form-error">{{ error }}</p>
    </div>
</template>

<script lang="ts">
import type { PropType } from 'vue';
import { defineComponent } from 'vue';

type SelectValue = string | number;
type SelectOption = { value: SelectValue; label: string };

let uid = 0;

export default defineComponent({
    name: 'SelectInput',

    props: {
        modelValue: {
            type: [String, Number] as PropType<SelectValue | null>,
            default: null,
        },
        /** [{ value, label }] or plain values (shown as they are). */
        options: {
            type: Array as PropType<(SelectOption | SelectValue)[]>,
            default: () => [],
        },
        label: { type: String, default: '' },
        placeholder: { type: String, default: '' },
        error: { type: String, default: '' },
        required: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            inputId: `select-${++uid}`,
        };
    },

    computed: {
        normalizedOptions(): SelectOption[] {
            return this.options.map((option) =>
                typeof option === 'object'
                    ? option
                    : { value: option, label: String(option) },
            );
        },
    },

    methods: {
        onChange(event: Event): void {
            const raw = (event.target as HTMLSelectElement).value;
            const match = this.normalizedOptions.find(
                (option) => String(option.value) === raw,
            );

            // Keep the original type of the option value (number ids stay numbers).
            this.$emit(
                'update:modelValue',
                raw === '' ? null : (match?.value ?? raw),
            );
        },
    },
});
</script>
