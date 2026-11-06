<template>
    <AppCard :title="$t('Servers')" flush>
        <template #actions>
            <Link
                :href="indexUrl"
                class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                {{ $t('View all') }}
                <ChevronRight class="size-4" />
            </Link>
        </template>

        <ul v-if="servers.length" class="divide-y divide-slate-100">
            <li v-for="server in servers" :key="server.id">
                <Link
                    :href="showUrl(server.id)"
                    class="block px-5 py-4 transition hover:bg-slate-50/80"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2">
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :class="dotClass(server.status)"
                                />
                                <span
                                    class="truncate text-sm font-semibold text-slate-900"
                                    >{{ server.name }}</span
                                >
                            </p>
                            <p
                                class="mt-0.5 truncate font-mono text-xs text-slate-500"
                            >
                                {{ server.address }}
                            </p>
                        </div>
                        <span
                            v-if="server.projects_count !== undefined"
                            class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                        >
                            {{
                                $t('1 project|:count projects', {
                                    count: server.projects_count,
                                })
                            }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-4">
                        <UsageBar
                            :label="$t('RAM')"
                            :usage="memoryUsage(server.info)"
                            small
                        />
                        <UsageBar
                            :label="$t('Disk')"
                            :usage="diskUsage(server.info)"
                            small
                        />
                    </div>

                    <p
                        v-if="isLowMemory(server.memory_mb)"
                        class="mt-3 flex items-start gap-1.5 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800"
                    >
                        <TriangleAlert
                            class="mt-px size-3.5 shrink-0 text-amber-500"
                        />
                        {{
                            $t('Under 2 GB of RAM: build locally recommended.')
                        }}
                    </p>
                    <p
                        v-else-if="server.status === 'failed'"
                        class="mt-3 flex items-start gap-1.5 rounded-md bg-rose-50 px-2.5 py-1.5 text-xs text-rose-700"
                    >
                        <CircleX
                            class="mt-px size-3.5 shrink-0 text-rose-500"
                        />
                        {{ $t('The last connection test failed.') }}
                    </p>
                </Link>
            </li>
        </ul>
        <EmptyState
            v-else
            :icon="ServerCog"
            :title="$t('No servers yet')"
            :description="$t('Add the server your projects are deployed to.')"
        >
            <AppButton :href="createUrl" size="sm" :icon="Plus">{{
                $t('Add server')
            }}</AppButton>
        </EmptyState>
    </AppCard>
</template>

<script lang="ts">
import {
    ChevronRight,
    CircleX,
    Plus,
    ServerCog,
    TriangleAlert,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import UsageBar from '@/components/modules/servers/UsageBar.vue';
import {
    diskUsage,
    isLowMemory,
    memoryUsage,
} from '@/components/modules/servers/usage';
import serverRoutes from '@/routes/servers';
import type { Server } from '@/types';

/** Dashboard panel: every server with its RAM and disk usage. */
export default defineComponent({
    name: 'ServersPanel',

    components: {
        AppButton,
        AppCard,
        ChevronRight,
        CircleX,
        EmptyState,
        Link,
        TriangleAlert,
        UsageBar,
    },

    props: {
        servers: { type: Array as PropType<Server[]>, required: true },
    },

    data() {
        return {
            Plus: markRaw(Plus),
            ServerCog: markRaw(ServerCog),
        };
    },

    computed: {
        indexUrl(): string {
            return serverRoutes.index().url;
        },

        createUrl(): string {
            return serverRoutes.create().url;
        },
    },

    methods: {
        diskUsage,
        isLowMemory,
        memoryUsage,

        showUrl(id: number): string {
            return serverRoutes.show(id).url;
        },

        dotClass(status: Server['status']): string {
            return (
                {
                    connected: 'bg-emerald-500',
                    failed: 'bg-rose-500',
                    unknown: 'bg-slate-300',
                }[status] ?? 'bg-slate-300'
            );
        },
    },
});
</script>
