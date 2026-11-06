<template>
    <Head :title="$t('New project')" />

    <PageHeader
        :title="$t('New project')"
        :description="
            $t(
                'A Laravel app from GitHub, deployed with zero downtime: each deploy is a new release, switched in when every step worked.',
            )
        "
        :back-href="indexUrl"
        :back-label="$t('Projects')"
    />

    <ProjectForm
        :initial="defaults"
        :servers="servers"
        :strategies="strategies"
        :is-windows="isWindows"
    />
</template>

<script lang="ts">
import { Head } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import PageHeader from '@/components/layout/PageHeader.vue';
import ProjectForm from '@/components/modules/projects/ProjectForm.vue';
import type {
    ProjectFormValues,
    ServerChoice,
} from '@/components/modules/projects/ProjectForm.vue';
import projectRoutes from '@/routes/projects';
import type { Card } from '@/types';

export default defineComponent({
    name: 'ProjectsCreate',

    components: { Head, PageHeader, ProjectForm },

    props: {
        servers: { type: Array as PropType<ServerChoice[]>, default: () => [] },
        strategies: { type: Array as PropType<Card[]>, default: () => [] },
        isWindows: { type: Boolean, default: false },
        defaults: {
            type: Object as PropType<ProjectFormValues>,
            default: () => ({}),
        },
    },

    computed: {
        indexUrl(): string {
            return projectRoutes.index().url;
        },
    },
});
</script>
