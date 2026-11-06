<template>
    <component
        :is="tag"
        :href="href"
        :target="external ? '_blank' : undefined"
        :rel="external ? 'noopener' : undefined"
        :type="href ? undefined : type"
        :disabled="href ? undefined : disabled || loading"
        class="inline-flex items-center justify-center gap-2 rounded-md font-medium whitespace-nowrap transition focus-visible:ring-4 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        :class="[variantClass, sizeClass]"
    >
        <LoaderCircle
            v-if="loading"
            class="animate-spin"
            :class="iconSizeClass"
        />
        <component :is="icon" v-else-if="icon" :class="iconSizeClass" />
        <slot />
    </component>
</template>

<script lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import type { UrlMethodPair } from '@inertiajs/core';
import type { Component, PropType } from 'vue';
import { defineComponent } from 'vue';

const VARIANTS = {
    primary:
        'bg-brand-600 text-white shadow-sm shadow-brand-600/30 hover:bg-brand-700 focus-visible:ring-brand-200',
    secondary:
        'border border-slate-300 bg-white text-slate-700 shadow-xs hover:bg-slate-50 focus-visible:ring-slate-200',
    success:
        'bg-emerald-600 text-white shadow-sm shadow-emerald-600/30 hover:bg-emerald-700 focus-visible:ring-emerald-200',
    danger: 'bg-rose-600 text-white shadow-sm shadow-rose-600/30 hover:bg-rose-700 focus-visible:ring-rose-200',
    ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-slate-200',
} as const;

const SIZES = {
    sm: 'px-3 py-2 text-sm',
    md: 'px-4 py-2.5 text-sm',
    icon: 'p-2',
} as const;

/** Button or Inertia link (when "href" is given) with the ERP look. */
export default defineComponent({
    name: 'AppButton',

    components: { Link, LoaderCircle },

    props: {
        variant: {
            type: String as PropType<keyof typeof VARIANTS>,
            default: 'primary',
        },
        size: { type: String as PropType<keyof typeof SIZES>, default: 'md' },
        href: {
            type: [String, Object] as PropType<string | UrlMethodPair>,
            default: undefined,
        },
        type: {
            type: String as PropType<'button' | 'submit'>,
            default: 'button',
        },
        icon: {
            type: [Object, Function] as PropType<Component>,
            default: undefined,
        },
        loading: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        /** Plain link opened in a new tab (e.g. the printable offer), not an Inertia visit. */
        external: { type: Boolean, default: false },
    },

    computed: {
        tag(): string {
            if (!this.href) {
                return 'button';
            }

            return this.external ? 'a' : 'Link';
        },

        variantClass(): string {
            return VARIANTS[this.variant];
        },

        sizeClass(): string {
            return SIZES[this.size];
        },

        iconSizeClass(): string {
            return this.size === 'sm' ? 'size-4' : 'size-[18px]';
        },
    },
});
</script>
