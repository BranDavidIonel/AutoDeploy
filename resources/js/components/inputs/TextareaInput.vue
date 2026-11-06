<template>
    <div>
        <label v-if="label" :for="inputId" class="form-label">{{
            label
        }}</label>
        <textarea
            :id="inputId"
            :value="modelValue ?? ''"
            :rows="rows"
            :placeholder="placeholder"
            class="form-control resize-y"
            :class="{ 'form-control-error': error }"
            @input="
                $emit(
                    'update:modelValue',
                    ($event.target as HTMLTextAreaElement).value,
                )
            "
        />
        <p v-if="error" class="form-error">{{ error }}</p>
    </div>
</template>

<script lang="ts">
import type { PropType } from 'vue';
import { defineComponent } from 'vue';

let uid = 0;

export default defineComponent({
    name: 'TextareaInput',

    props: {
        modelValue: { type: String as PropType<string | null>, default: null },
        label: { type: String, default: '' },
        placeholder: { type: String, default: '' },
        error: { type: String, default: '' },
        rows: { type: Number, default: 3 },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            inputId: `textarea-${++uid}`,
        };
    },
});
</script>
