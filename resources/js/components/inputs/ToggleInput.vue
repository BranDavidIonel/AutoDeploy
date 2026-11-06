<template>
    <label class="flex cursor-pointer items-start gap-3">
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:ring-4 focus-visible:ring-brand-100 focus-visible:outline-none"
            :class="modelValue ? 'bg-brand-600' : 'bg-slate-300'"
            @click="$emit('update:modelValue', !modelValue)"
        >
            <span
                class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition-transform"
                :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
            />
        </button>
        <span>
            <span class="block text-sm font-medium text-slate-700">{{
                label
            }}</span>
            <span v-if="description" class="block text-xs text-slate-500">{{
                description
            }}</span>
        </span>
    </label>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'ToggleInput',

    props: {
        modelValue: { type: Boolean, default: false },
        label: { type: String, required: true },
        description: { type: String, default: '' },
    },

    emits: ['update:modelValue'],
});
</script>
