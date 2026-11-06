<template>
    <Link
        :href="showUrl"
        class="group card hover:shadow-brand-900/5 flex flex-col p-5 transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset"
                    :class="
                        server.status === 'connected'
                            ? 'bg-emerald-50 text-emerald-600 ring-emerald-100'
                            : server.status === 'failed'
                              ? 'bg-rose-50 text-rose-600 ring-rose-100'
                              : 'bg-slate-100 text-slate-500 ring-slate-200'
                    "
                >
                    <Laptop v-if="server.is_local" class="size-5" />
                    <ServerIcon v-else class="size-5" />
                </span>
                <div class="min-w-0">
                    <h3
                        class="truncate text-base font-semibold text-slate-900 transition group-hover:text-brand-700"
                    >
                        {{ server.name }}
                    </h3>
                    <p class="truncate font-mono text-xs text-slate-500">
                        {{ server.address }}
                    </p>
                </div>
            </div>
            <StatusBadge
                class="shrink-0"
                :status="server.status"
                :label="
                    server.status === 'failed' ? $t('Connection failed') : ''
                "
            />
        </div>

        <p
            v-if="server.info.os"
            class="mt-3 truncate text-xs text-slate-500"
            :title="server.info.os"
        >
            {{ server.info.os }}
            <template v-if="server.info.cpus">
                · {{ $t(':count CPU', { count: server.info.cpus }) }}</template
            >
        </p>

        <div class="mt-4 space-y-3">
            <UsageBar
                :label="$t('RAM')"
                :usage="memory"
                :empty="$t('not checked yet')"
            />
            <UsageBar
                :label="$t('Disk')"
                :usage="disk"
                :empty="$t('not checked yet')"
            />
        </div>

        <p
            v-if="lowMemory"
            class="mt-3 flex items-start gap-1.5 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800"
        >
            <TriangleAlert class="mt-px size-3.5 shrink-0 text-amber-500" />
            {{ $t('Under 2 GB of RAM: build locally recommended.') }}
        </p>

        <div class="flex-1" />

        <footer
            class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 text-xs text-slate-500"
        >
            <span class="inline-flex items-center gap-1.5">
                <FolderGit2 class="size-3.5 text-slate-400" />
                {{
                    $t('1 project|:count projects', {
                        count: server.projects_count ?? 0,
                    })
                }}
            </span>
            <span
                class="inline-flex min-w-0 items-center gap-1.5"
                :title="formatDateTime(server.last_checked_at)"
            >
                <Clock class="size-3.5 shrink-0 text-slate-400" />
                <span class="truncate">{{
                    server.last_checked_at
                        ? $t('checked :when', {
                              when: timeAgo(server.last_checked_at),
                          })
                        : $t('never checked')
                }}</span>
            </span>
        </footer>
    </Link>
</template>

<script lang="ts">
import {
    Clock,
    FolderGit2,
    Laptop,
    Server as ServerIcon,
    TriangleAlert,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import UsageBar from '@/components/modules/servers/UsageBar.vue';
import type { Usage } from '@/components/modules/servers/usage';
import {
    diskUsage,
    isLowMemory,
    memoryUsage,
} from '@/components/modules/servers/usage';
import serverRoutes from '@/routes/servers';
import type { Server } from '@/types';
import { formatDateTime, timeAgo } from '@/utils/format';

/** Server tile for the servers list: status, RAM, disk, projects, last check. */
export default defineComponent({
    name: 'ServerCard',

    components: {
        Clock,
        FolderGit2,
        Laptop,
        Link,
        ServerIcon,
        StatusBadge,
        TriangleAlert,
        UsageBar,
    },

    props: {
        server: { type: Object as PropType<Server>, required: true },
    },

    computed: {
        showUrl(): string {
            return serverRoutes.show(this.server.id).url;
        },

        memory(): Usage | null {
            return memoryUsage(this.server.info);
        },

        disk(): Usage | null {
            return diskUsage(this.server.info);
        },

        lowMemory(): boolean {
            return isLowMemory(this.server.memory_mb);
        },
    },

    methods: {
        formatDateTime,
        timeAgo,
    },
});
</script>
