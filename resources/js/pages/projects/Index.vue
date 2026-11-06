<template>
    <Head :title="$t('Projects')" />

    <PageHeader
        :title="$t('Projects')"
        :description="
            $t(
                'Every Laravel app AutoDeploy deploys, with its last deployment.',
            )
        "
    >
        <template #actions>
            <AppButton v-if="hasServers" :href="createUrl" :icon="Plus">{{
                $t('New project')
            }}</AppButton>
            <AppButton v-else :href="serverCreateUrl" :icon="ServerCog">{{
                $t('Add server')
            }}</AppButton>
        </template>
    </PageHeader>

    <template v-if="projects.length">
        <!-- Search (only worth it with a few projects) -->
        <div v-if="projects.length > 6" class="mb-4 max-w-sm">
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
                />
                <input
                    v-model="search"
                    type="search"
                    class="form-control pl-9"
                    :placeholder="$t('Search projects…')"
                />
            </div>
        </div>

        <div
            v-if="filtered.length"
            class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <ProjectCard
                v-for="project in filtered"
                :key="project.id"
                :project="project"
                large
            />
        </div>
        <p v-else class="card px-6 py-10 text-center text-sm text-slate-500">
            {{ $t('No project matches “:search”.', { search }) }}
        </p>
    </template>

    <!-- No projects yet -->
    <div v-else class="card">
        <EmptyState
            v-if="hasServers"
            :icon="FolderGit2"
            :title="$t('No projects yet')"
            :description="
                $t(
                    'Add your first Laravel project: the GitHub repository, the branch and a new folder on the server.',
                )
            "
        >
            <AppButton :href="createUrl" :icon="Plus">{{
                $t('New project')
            }}</AppButton>
        </EmptyState>
        <EmptyState
            v-else
            :icon="ServerCog"
            :title="$t('Add a server first')"
            :description="
                $t(
                    'A project is deployed to a server. Add the VPS your app runs on (or this machine), then come back to create the project.',
                )
            "
        >
            <AppButton :href="serverCreateUrl" :icon="ServerCog">{{
                $t('Add server')
            }}</AppButton>
        </EmptyState>
    </div>
</template>

<script lang="ts">
import { FolderGit2, Plus, Search, ServerCog } from '@lucide/vue';
import { Head } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import PageHeader from '@/components/layout/PageHeader.vue';
import ProjectCard from '@/components/modules/projects/ProjectCard.vue';
import projectRoutes from '@/routes/projects';
import serverRoutes from '@/routes/servers';
import type { Project } from '@/types';

/** All projects as cards: last deployment, live release, server, branch, assets. */
export default defineComponent({
    name: 'ProjectsIndex',

    components: {
        AppButton,
        EmptyState,
        Head,
        PageHeader,
        ProjectCard,
        Search,
    },

    props: {
        projects: { type: Array as PropType<Project[]>, required: true },
        hasServers: { type: Boolean, default: true },
    },

    data() {
        return {
            search: '',
            FolderGit2: markRaw(FolderGit2),
            Plus: markRaw(Plus),
            ServerCog: markRaw(ServerCog),
        };
    },

    computed: {
        filtered(): Project[] {
            const term = this.search.trim().toLowerCase();

            if (!term) {
                return this.projects;
            }

            return this.projects.filter((project) =>
                [
                    project.name,
                    project.repository,
                    project.branch,
                    project.server?.name ?? '',
                    project.url ?? '',
                ].some((value) => value.toLowerCase().includes(term)),
            );
        },

        createUrl(): string {
            return projectRoutes.create().url;
        },

        serverCreateUrl(): string {
            return serverRoutes.create().url;
        },
    },
});
</script>
