<template>
    <AppCard :title="$t('Resources')">
        <template #actions>
            <span
                v-if="checkedAt"
                class="text-xs text-slate-500"
                :title="formatDateTime(checkedAt)"
                >{{ $t('checked :when', { when: timeAgo(checkedAt) }) }}</span
            >
        </template>

        <div class="space-y-5">
            <UsageBar
                :label="$t('RAM')"
                :usage="memory"
                :detail="
                    memory
                        ? $t(':size available', {
                              size: formatMegabytes(memory.free),
                          })
                        : ''
                "
                :empty="$t('not checked yet')"
            />
            <UsageBar
                :label="$t('Swap')"
                :usage="swap"
                :detail="
                    swap
                        ? $t(':size free', { size: formatMegabytes(swap.free) })
                        : ''
                "
                :empty="checked ? $t('no swap') : $t('not checked yet')"
            />
            <UsageBar
                :label="$t('Disk (/)')"
                :usage="disk"
                :detail="
                    disk
                        ? $t(':size free', { size: formatMegabytes(disk.free) })
                        : ''
                "
                :empty="$t('not checked yet')"
            />
        </div>

        <div
            class="mt-6 grid grid-cols-3 divide-x divide-slate-100 rounded-lg bg-slate-50 py-3 ring-1 ring-slate-100 ring-inset"
        >
            <div
                v-for="item in figures"
                :key="item.label"
                class="min-w-0 px-3 text-center"
            >
                <p
                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    {{ item.label }}
                </p>
                <p
                    class="mt-1 truncate text-sm font-semibold text-slate-800 tabular-nums"
                    :title="item.value"
                >
                    {{ item.value }}
                </p>
            </div>
        </div>
    </AppCard>
</template>

<script lang="ts">
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import AppCard from '@/components/general/AppCard.vue';
import UsageBar from '@/components/modules/servers/UsageBar.vue';
import type { Usage } from '@/components/modules/servers/usage';
import {
    diskUsage,
    memoryUsage,
    swapUsage,
} from '@/components/modules/servers/usage';
import type { ServerInfo } from '@/types';
import { formatDateTime, formatMegabytes, timeAgo } from '@/utils/format';

/** RAM, swap and disk bars plus CPUs / load / uptime. */
export default defineComponent({
    name: 'ServerResources',

    components: { AppCard, UsageBar },

    props: {
        info: { type: Object as PropType<ServerInfo>, required: true },
        checked: { type: Boolean, default: false },
        checkedAt: { type: String as PropType<string | null>, default: null },
    },

    computed: {
        memory(): Usage | null {
            return memoryUsage(this.info);
        },

        swap(): Usage | null {
            return swapUsage(this.info);
        },

        disk(): Usage | null {
            return diskUsage(this.info);
        },

        figures(): { label: string; value: string }[] {
            return [
                { label: this.$t('CPUs'), value: this.info.cpus || '—' },
                { label: this.$t('Load'), value: this.info.load || '—' },
                { label: this.$t('Uptime'), value: this.info.uptime || '—' },
            ];
        },
    },

    methods: {
        formatDateTime,
        formatMegabytes,
        timeAgo,
    },
});
</script>
