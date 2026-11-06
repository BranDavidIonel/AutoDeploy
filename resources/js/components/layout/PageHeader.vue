<template>
    <div
        class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="min-w-0">
            <Link
                v-if="backHref"
                :href="backHref"
                class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-brand-600"
            >
                <ArrowLeft class="size-4" />
                {{ backLabel || $t('Back') }}
            </Link>
            <div class="flex flex-wrap items-center gap-3">
                <h1
                    class="truncate text-2xl font-semibold tracking-tight text-slate-900"
                >
                    {{ title }}
                </h1>
                <slot name="badge" />
            </div>
            <p v-if="description" class="mt-1 text-sm text-slate-500">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>

<script lang="ts">
import { ArrowLeft } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'PageHeader',

    components: { ArrowLeft, Link },

    props: {
        title: { type: String, required: true },
        description: { type: String, default: '' },
        backHref: { type: String, default: '' },
        backLabel: { type: String, default: '' },
    },
});
</script>
