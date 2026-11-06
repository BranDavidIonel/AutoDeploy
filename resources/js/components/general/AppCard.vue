<template>
    <section class="card">
        <header
            v-if="title || $slots.actions"
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-slate-100 px-5 py-4"
        >
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-slate-900">
                    {{ title }}
                </h2>
                <p v-if="description" class="mt-0.5 text-sm text-slate-500">
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>
        <div :class="flush ? '' : 'p-5'">
            <slot />
        </div>
    </section>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'AppCard',

    props: {
        title: { type: String, default: '' },
        description: { type: String, default: '' },
        /** No inner padding (for tables that touch the card edges). */
        flush: { type: Boolean, default: false },
    },
});
</script>
