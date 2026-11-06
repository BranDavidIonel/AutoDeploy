<template>
    <div class="card relative overflow-hidden p-5">
        <div class="relative flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-500">{{ label }}</p>
                <p
                    class="mt-2 truncate text-2xl font-semibold tracking-tight text-slate-900"
                >
                    {{ value }}
                </p>
                <p v-if="hint" class="mt-1 text-xs text-slate-500">
                    {{ hint }}
                </p>
            </div>
            <div
                v-if="icon"
                class="flex size-11 shrink-0 items-center justify-center rounded-md"
                :class="tone.icon"
            >
                <component :is="icon" class="size-5" />
            </div>
        </div>
    </div>
</template>

<script lang="ts">
import type { Component, PropType } from 'vue';
import { defineComponent } from 'vue';

const TONES = {
    brand: { icon: 'bg-brand-50 text-brand-600', glow: 'bg-brand-100' },
    emerald: { icon: 'bg-emerald-50 text-emerald-600', glow: 'bg-emerald-100' },
    amber: { icon: 'bg-amber-50 text-amber-600', glow: 'bg-amber-100' },
    rose: { icon: 'bg-rose-50 text-rose-600', glow: 'bg-rose-100' },
    sky: { icon: 'bg-sky-50 text-sky-600', glow: 'bg-sky-100' },
    violet: { icon: 'bg-violet-50 text-violet-600', glow: 'bg-violet-100' },
} as const;

/** KPI tile: label, big value, optional hint and icon. */
export default defineComponent({
    name: 'StatCard',

    props: {
        label: { type: String, required: true },
        value: { type: [String, Number], required: true },
        hint: { type: String, default: '' },
        icon: {
            type: [Object, Function] as PropType<Component>,
            default: undefined,
        },
        color: {
            type: String as PropType<keyof typeof TONES>,
            default: 'brand',
        },
    },

    computed: {
        tone(): (typeof TONES)[keyof typeof TONES] {
            return TONES[this.color];
        },
    },
});
</script>
