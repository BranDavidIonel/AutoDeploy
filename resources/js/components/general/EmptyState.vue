<template>
    <div
        class="flex flex-col items-center justify-center px-6 py-14 text-center"
    >
        <div
            class="flex size-14 items-center justify-center rounded-lg bg-slate-100 text-slate-400"
        >
            <component :is="icon || 'Inbox'" class="size-7" />
        </div>
        <h3 class="mt-4 text-base font-semibold text-slate-800">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-slate-500">
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-5">
            <slot />
        </div>
    </div>
</template>

<script lang="ts">
import { Inbox } from '@lucide/vue';
import type { Component, PropType } from 'vue';
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'EmptyState',

    components: { Inbox },

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
