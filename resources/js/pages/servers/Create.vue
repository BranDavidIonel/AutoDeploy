<template>
    <Head :title="$t('Add a server')" />

    <PageHeader
        :title="$t('Add a server')"
        :description="
            $t(
                'The machine your Laravel projects are deployed to. AutoDeploy checks the connection right after saving.',
            )
        "
        :back-href="indexUrl"
        :back-label="$t('Servers')"
    />

    <ServerForm
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
import PageHeader from '@/components/layout/PageHeader.vue';
import ServerForm from '@/components/modules/servers/ServerForm.vue';
import serverRoutes from '@/routes/servers';
import type { Option } from '@/types';

export default defineComponent({
    name: 'ServersCreate',

    components: { Head, PageHeader, ServerForm },

    props: {
        connectionTypes: {
            type: Array as PropType<Option[]>,
            default: () => [],
        },
        authMethods: { type: Array as PropType<Option[]>, default: () => [] },
        defaultKeyPath: { type: String, default: '~/.ssh/id_ed25519' },
        isWindows: { type: Boolean, default: false },
    },

    computed: {
        indexUrl(): string {
            return serverRoutes.index().url;
        },
    },
});
</script>
