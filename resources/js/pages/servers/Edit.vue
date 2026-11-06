<template>
    <Head :title="$t('Edit :name', { name: server.name })" />

    <PageHeader
        :title="$t('Edit :name', { name: server.name })"
        :description="server.address"
        :back-href="showUrl"
        :back-label="server.name"
    >
        <template #badge>
            <StatusBadge :status="server.status" :label="statusLabel" />
        </template>
    </PageHeader>

    <ServerForm
        :server="server"
        :connection-types="connectionTypes"
        :auth-methods="authMethods"
        :default-key-path="defaultKeyPath"
        :is-windows="isWindows"
    />
</template>

<script lang="ts">
import { Head } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import PageHeader from '@/components/layout/PageHeader.vue';
import ServerForm from '@/components/modules/servers/ServerForm.vue';
import serverRoutes from '@/routes/servers';
import type { Option, Server } from '@/types';

export default defineComponent({
    name: 'ServersEdit',

    components: { Head, PageHeader, ServerForm, StatusBadge },

    props: {
        server: { type: Object as PropType<Server>, required: true },
        connectionTypes: {
            type: Array as PropType<Option[]>,
            default: () => [],
        },
        authMethods: { type: Array as PropType<Option[]>, default: () => [] },
        defaultKeyPath: { type: String, default: '~/.ssh/id_ed25519' },
        isWindows: { type: Boolean, default: false },
    },

    computed: {
        showUrl(): string {
            return serverRoutes.show(this.server.id).url;
        },

        statusLabel(): string {
            return this.server.status === 'failed'
                ? this.$t('Connection failed')
                : '';
        },
    },
});
</script>
