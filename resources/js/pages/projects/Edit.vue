<template>
    <Head :title="$t(':name settings', { name: project.name })" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="settings"
    >
        <ProjectForm
            :key="project.id"
            :initial="project"
            :servers="servers"
            :strategies="strategies"
            :is-windows="isWindows"
            :project-id="project.id"
        />

        <div class="mt-10 grid grid-cols-1 gap-x-8 gap-y-6 lg:grid-cols-3">
            <div class="hidden lg:block">
                <h2 class="text-base font-semibold text-slate-900">
                    {{ $t('Webhook and danger zone') }}
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-500">
                    {{
                        $t(
                            'Deploy automatically from GitHub, or remove the project from AutoDeploy.',
                        )
                    }}
                </p>
            </div>

            <div class="space-y-6 lg:col-span-2">
                <WebhookCard :project="project" :webhook-url="webhookUrl" />

                <!-- Danger zone -->
                <section class="card overflow-hidden border-rose-200">
                    <header
                        class="border-b border-rose-100 bg-rose-50/60 px-5 py-4"
                    >
                        <h2 class="text-base font-semibold text-rose-900">
                            {{ $t('Danger zone') }}
                        </h2>
                    </header>
                    <div
                        class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $t('Delete this project') }}
                            </p>
                            <p class="mt-1 text-sm text-slate-500">
                                {{
                                    $t(
                                        'Removes it from AutoDeploy with its deployment history and saved builds. Nothing is deleted on the server: the releases, shared files and the live site stay as they are.',
                                    )
                                }}
                            </p>
                        </div>
                        <AppButton
                            variant="danger"
                            :icon="Trash2"
                            class="shrink-0"
                            :disabled="!!activeDeployment"
                            @click="deleting = true"
                            >{{ $t('Delete project') }}</AppButton
                        >
                    </div>
                </section>
            </div>
        </div>
    </ProjectShell>

    <ConfirmModal
        :show="deleting"
        :title="$t('Delete :name?', { name: project.name })"
        :message="
            $t(
                'The project, its deployment history and saved builds are removed from AutoDeploy. The files on the server and the live site are not touched.',
            )
        "
        :confirm-label="$t('Delete project')"
        :processing="destroying"
        @close="deleting = false"
        @confirm="destroy"
    />
</template>

<script lang="ts">
import { Trash2 } from '@lucide/vue';
import { Head, router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import ProjectForm from '@/components/modules/projects/ProjectForm.vue';
import type { ServerChoice } from '@/components/modules/projects/ProjectForm.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import WebhookCard from '@/components/modules/projects/WebhookCard.vue';
import projectRoutes from '@/routes/projects';
import type { Build, Card, Deployment, Project } from '@/types';

/** Project settings tab: the form, the GitHub webhook and deleting the project. */
export default defineComponent({
    name: 'ProjectsEdit',

    components: {
        AppButton,
        ConfirmModal,
        Head,
        ProjectForm,
        ProjectShell,
        WebhookCard,
    },

    props: {
        project: { type: Object as PropType<Project>, required: true },
        activeDeployment: {
            type: Object as PropType<Deployment | null>,
            default: null,
        },
        deployOptions: {
            type: Object as PropType<{ strategies: Card[]; builds: Build[] }>,
            required: true,
        },
        servers: { type: Array as PropType<ServerChoice[]>, default: () => [] },
        strategies: { type: Array as PropType<Card[]>, default: () => [] },
        isWindows: { type: Boolean, default: false },
        webhookUrl: { type: String, required: true },
    },

    data() {
        return {
            deleting: false,
            destroying: false,
            Trash2: markRaw(Trash2),
        };
    },

    methods: {
        destroy(): void {
            router.delete(projectRoutes.destroy(this.project.id).url, {
                onStart: () => (this.destroying = true),
                onFinish: () => (this.destroying = false),
                onSuccess: () => (this.deleting = false),
            });
        },
    },
});
</script>
