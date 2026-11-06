<template>
    <div>
        <div class="flex items-baseline justify-between gap-3 text-xs">
            <span class="font-medium text-slate-600">{{ label }}</span>
            <span v-if="usage" class="text-slate-500 tabular-nums">
                <span class="font-semibold text-slate-800">{{
                    formatMegabytes(usage.used)
                }}</span>
                / {{ formatMegabytes(usage.total) }}
                <span class="ml-1 text-slate-400">{{ usage.percent }}%</span>
            </span>
            <span v-else class="text-slate-400">{{ empty || '—' }}</span>
        </div>
        <div
            class="mt-1.5 overflow-hidden rounded-full bg-slate-100"
            :class="small ? 'h-1.5' : 'h-2'"
        >
            <div
                v-if="usage"
                class="h-full rounded-full transition-all duration-500"
                :class="color"
                :style="{ width: `${Math.max(usage.percent, 2)}%` }"
            />
        </div>
        <p v-if="usage && detail" class="mt-1 text-xs text-slate-500">
            {{ detail }}
        </p>
    </div>
</template>

<script lang="ts">
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import type { Usage } from '@/components/modules/servers/usage';
import { usageColor } from '@/components/modules/servers/usage';
import { formatMegabytes } from '@/utils/format';

/** "RAM 1.2 GB / 2 GB 60 %" with a colored bar (green → amber → red). */
export default defineComponent({
    name: 'UsageBar',

    props: {
        label: { type: String, required: true },
        usage: { type: Object as PropType<Usage | null>, default: null },
        /** Small line under the bar, e.g. "800 MB available". */
        detail: { type: String, default: '' },
        /** Text when the figure is unknown. */
        empty: { type: String, default: '' },
        small: { type: Boolean, default: false },
    },

    computed: {
        color(): string {
            return this.usage ? usageColor(this.usage.percent) : '';
        },
    },

    methods: {
        formatMegabytes,
    },
});
</script>
