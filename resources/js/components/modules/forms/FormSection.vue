<template>
    <section class="grid grid-cols-1 gap-x-8 gap-y-4 lg:grid-cols-3">
        <div class="lg:pt-1">
            <div class="flex items-center gap-2.5">
                <span
                    v-if="icon"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100 ring-inset"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <h2 class="text-base font-semibold text-slate-900">
                    {{ title }}
                </h2>
            </div>
            <p
                v-if="description"
                class="mt-2 text-sm leading-relaxed text-slate-500"
            >
                {{ description }}
            </p>
            <slot name="aside" />
        </div>
        <div class="card space-y-5 p-5 sm:p-6 lg:col-span-2">
            <slot />
        </div>
    </section>
</template>

<script lang="ts">
import type { Component, PropType } from 'vue';
import { defineComponent } from 'vue';

/** Settings-style block: title and explanation on the left, the fields in a card on the right. */
export default defineComponent({
    name: 'FormSection',

    props: {
        title: { type: String, required: true },
        description: { type: String, default: '' },
        icon: {
            type: [Object, Function] as PropType<Component>,
            default: undefined,
        },
    },
});
</script>
