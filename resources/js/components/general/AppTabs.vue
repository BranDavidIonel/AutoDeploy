<template>
    <!-- The gray base line is an inset shadow, so the active tab's border can sit on it while the bar scrolls. -->
    <div class="shadow-[inset_0_-1px_0_var(--color-slate-200)]">
        <div
            ref="list"
            class="flex [scrollbar-width:none] overflow-x-auto px-2"
            role="tablist"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="tab.key === modelValue"
                :data-tab="tab.key"
                class="flex shrink-0 items-center gap-2 border-b-2 px-3 py-3.5 text-sm font-medium whitespace-nowrap transition-colors"
                :class="
                    tab.key === modelValue
                        ? 'border-brand-600 text-brand-700'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'
                "
                @click="$emit('update:modelValue', tab.key)"
            >
                <component
                    :is="tab.icon"
                    v-if="tab.icon"
                    class="size-4"
                    :class="tab.key === modelValue ? '' : 'text-slate-400'"
                />
                {{ tab.label }}
                <span
                    v-if="tab.count !== undefined"
                    class="min-w-5 rounded-full px-1.5 py-0.5 text-center text-[11px] leading-none font-semibold"
                    :class="badgeClass(tab)"
                >
                    {{ tab.count }}
                </span>
            </button>
        </div>
    </div>
</template>

<script lang="ts">
import type { Component, PropType } from 'vue';
import { defineComponent } from 'vue';

export type Tab = {
    key: string;
    label: string;
    icon?: Component;
    count?: number;
    /** Color of the count when it is not zero: "alert" (red) or "warning" (amber) for work that waits. */
    tone?: 'neutral' | 'alert' | 'warning';
};

/** Underlined tabs with an optional icon and count; v-model is the key of the open tab. Scrolls sideways on phones. */
export default defineComponent({
    name: 'AppTabs',

    props: {
        tabs: { type: Array as PropType<Tab[]>, required: true },
        modelValue: { type: String, required: true },
    },

    emits: ['update:modelValue'],

    watch: {
        modelValue() {
            this.$nextTick(() => this.revealActive());
        },
    },

    mounted() {
        this.revealActive();
    },

    methods: {
        /** On phones the bar scrolls sideways: keep the open tab in view (only the bar moves, not the page). */
        revealActive(): void {
            const list = this.$refs.list as HTMLElement | undefined;
            const active = list?.querySelector<HTMLElement>(
                `[data-tab="${this.modelValue}"]`,
            );

            if (!list || !active) {
                return;
            }

            const left = active.offsetLeft - list.offsetLeft;
            const right = left + active.offsetWidth;

            if (left < list.scrollLeft) {
                list.scrollLeft = left - 8;
            } else if (right > list.scrollLeft + list.clientWidth) {
                list.scrollLeft = right - list.clientWidth + 8;
            }
        },

        badgeClass(tab: Tab): string {
            if (!tab.count) {
                return 'bg-slate-100 text-slate-400';
            }

            switch (tab.tone) {
                case 'alert':
                    return 'bg-rose-100 text-rose-700';
                case 'warning':
                    return 'bg-amber-100 text-amber-700';
                default:
                    return tab.key === this.modelValue
                        ? 'bg-brand-100 text-brand-700'
                        : 'bg-slate-100 text-slate-600';
            }
        },
    },
});
</script>
