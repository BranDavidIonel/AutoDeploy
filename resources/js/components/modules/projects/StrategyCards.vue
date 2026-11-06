<template>
    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2" role="radiogroup">
        <button
            v-for="strategy in strategies"
            :key="strategy.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === strategy.value"
            class="relative flex gap-3 rounded-lg border p-3 text-left transition"
            :class="
                modelValue === strategy.value
                    ? 'border-brand-500 bg-brand-50/60 ring-4 ring-brand-100'
                    : 'border-slate-200 hover:border-slate-300'
            "
            @click="select(strategy.value)"
        >
            <span
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md"
                :class="
                    modelValue === strategy.value
                        ? 'bg-brand-600 text-white'
                        : 'bg-slate-100 text-slate-500'
                "
            >
                <component :is="icon(strategy.value)" class="size-4" />
            </span>
            <span class="min-w-0">
                <span
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-slate-900"
                >
                    {{ strategy.label }}
                    <span
                        v-if="strategy.value === recommended"
                        class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-emerald-700 uppercase ring-1 ring-emerald-100"
                        >{{ $t('recommended') }}</span
                    >
                </span>
                <span
                    class="mt-0.5 block text-xs leading-relaxed text-slate-500"
                    >{{ strategy.description }}</span
                >
            </span>
        </button>
    </div>
</template>

<script lang="ts">
import { Ban, Cloud, Laptop, Upload } from '@lucide/vue';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import type { BuildStrategy, Card } from '@/types';

const ICONS: Record<string, Component> = {
    local: markRaw(Laptop),
    upload: markRaw(Upload),
    server: markRaw(Cloud),
    none: markRaw(Ban),
};

/** The 4 ways to get the front-end assets (build locally, saved build, on the server, none) as cards; v-model = value. */
export default defineComponent({
    name: 'StrategyCards',

    props: {
        modelValue: { type: String as PropType<BuildStrategy>, required: true },
        strategies: { type: Array as PropType<Card[]>, required: true },
        /** Marked with a small "recommended" tag. */
        recommended: { type: String, default: '' },
    },

    emits: {
        'update:modelValue': (value: BuildStrategy) =>
            typeof value === 'string',
    },

    methods: {
        select(value: string): void {
            this.$emit('update:modelValue', value as BuildStrategy);
        },

        icon(value: string): Component {
            return ICONS[value] ?? ICONS.none;
        },
    },
});
</script>
