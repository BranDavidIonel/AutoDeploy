<template>
    <Head :title="$t('Servers')" />

    <PageHeader
        :title="$t('Servers')"
        :description="$t('The machines your projects are deployed to.')"
    >
        <template #actions>
            <AppButton :href="createUrl" :icon="Plus">{{
                $t('Add server')
            }}</AppButton>
        </template>
    </PageHeader>

    <div
        v-if="servers.length"
        class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
    >
        <ServerCard
            v-for="server in servers"
            :key="server.id"
            :server="server"
        />
    </div>

    <div v-else class="card">
        <EmptyState
            :icon="ServerCog"
            :title="$t('No servers yet')"
            :description="
                $t(
                    'Add the VPS your Laravel projects run on. AutoDeploy connects to it over SSH, or runs the commands directly when it is installed on the server itself.',
                )
            "
        >
            <AppButton :href="createUrl" :icon="Plus">{{
                $t('Add server')
            }}</AppButton>
        </EmptyState>
    </div>
</template>

<script lang="ts">
import { Plus, ServerCog } from '@lucide/vue';
import { Head } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import PageHeader from '@/components/layout/PageHeader.vue';
import ServerCard from '@/components/modules/servers/ServerCard.vue';
import serverRoutes from '@/routes/servers';
import type { Server } from '@/types';

/** All servers with their status, RAM and disk. */
export default defineComponent({
    name: 'ServersIndex',

    components: { AppButton, EmptyState, Head, PageHeader, ServerCard },

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
        createUrl(): string {
            return serverRoutes.create().url;
        },
    },
});
</script>
