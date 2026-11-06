<template>
    <Head :title="`${$t('Builds')} · ${project.name}`" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="builds"
        :reload-only="['builds']"
    >
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            <!-- Upload -->
            <AppCard
                class="lg:col-span-3"
                :title="$t('Upload a build')"
                :description="
                    $t(
                        'The public/build folder made by npm run build on your PC, as a .zip or .tar.gz.',
                    )
                "
            >
                <form
                    id="upload-form"
                    class="space-y-5"
                    @submit.prevent="upload"
                >
                    <!-- Drop zone -->
                    <div>
                        <label
                            class="group relative flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-8 text-center transition"
                            :class="dropZoneClass"
                            @dragenter.prevent="dragging = true"
                            @dragover.prevent="dragging = true"
                            @dragleave.prevent="onDragLeave"
                            @drop.prevent="onDrop"
                        >
                            <input
                                ref="file"
                                type="file"
                                class="sr-only"
                                accept=".zip,.gz,.tgz,.tar,application/zip,application/gzip,application/x-gzip"
                                @change="onFileChosen"
                            />

                            <template v-if="form.archive">
                                <span
                                    class="flex size-12 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm shadow-brand-600/30"
                                >
                                    <FileArchive class="size-6" />
                                </span>
                                <span
                                    class="mt-3 max-w-full truncate text-sm font-semibold text-slate-900"
                                    >{{ form.archive.name }}</span
                                >
                                <span class="mt-0.5 text-xs text-slate-500">{{
                                    formatBytes(form.archive.size)
                                }}</span>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                                    :disabled="form.processing"
                                    @click.prevent="clearFile"
                                >
                                    <X class="size-3.5" />
                                    {{ $t('Choose another file') }}
                                </button>
                            </template>

                            <template v-else>
                                <span
                                    class="flex size-12 items-center justify-center rounded-xl transition"
                                    :class="
                                        dragging
                                            ? 'bg-brand-600 text-white'
                                            : 'bg-slate-100 text-slate-500 group-hover:bg-brand-100 group-hover:text-brand-700'
                                    "
                                >
                                    <UploadCloud class="size-6" />
                                </span>
                                <span
                                    class="mt-3 text-sm font-semibold text-slate-900"
                                >
                                    {{
                                        dragging
                                            ? $t('Drop it here')
                                            : $t('Drag the archive here')
                                    }}
                                </span>
                                <span class="mt-0.5 text-sm text-slate-500">
                                    {{ $t('or') }}
                                    <span
                                        class="font-medium text-brand-600 group-hover:text-brand-700"
                                        >{{ $t('choose a file') }}</span
                                    >
                                </span>
                                <span class="mt-2 text-xs text-slate-400">
                                    .zip · .tar.gz · .tgz ·
                                    {{
                                        $t('max :size MB', {
                                            size: maxUploadMb,
                                        })
                                    }}
                                </span>
                            </template>
                        </label>
                        <p
                            v-if="fileError || form.errors.archive"
                            class="form-error"
                        >
                            {{ fileError || form.errors.archive }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <TextInput
                            v-model="form.label"
                            :label="$t('Label (optional)')"
                            :placeholder="$t('e.g. new invoice page')"
                            :error="form.errors.label"
                        />
                        <TextInput
                            v-model="form.commit_hash"
                            :label="$t('Commit (optional)')"
                            placeholder="a1b2c3d"
                            input-class="font-mono"
                            :hint="
                                $t(
                                    'The commit this build was made from: then that exact commit is deployed.',
                                )
                            "
                            :error="form.errors.commit_hash"
                        />
                    </div>

                    <ToggleInput
                        v-model="form.deploy"
                        :label="$t('Deploy right after upload')"
                        :description="
                            activeDeployment
                                ? $t(
                                      'A deployment is running: the build will only be saved.',
                                  )
                                : $t(
                                      'Starts a deploy with this build as soon as it is saved.',
                                  )
                        "
                    />

                    <!-- Upload progress -->
                    <div v-if="form.processing" class="space-y-1.5">
                        <div
                            class="flex items-center justify-between text-xs font-medium text-slate-600"
                        >
                            <span>{{
                                uploadPercent < 100
                                    ? $t('Uploading…')
                                    : $t('Unpacking and checking the build…')
                            }}</span>
                            <span class="tabular-nums"
                                >{{ uploadPercent }}%</span
                            >
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-slate-100"
                        >
                            <div
                                class="h-full rounded-full bg-linear-to-r from-brand-500 to-violet-500 transition-all duration-300"
                                :class="{
                                    'animate-pulse': uploadPercent >= 100,
                                }"
                                :style="{ width: `${uploadPercent}%` }"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <AppButton
                            type="submit"
                            :icon="
                                form.deploy && !activeDeployment
                                    ? Rocket
                                    : Upload
                            "
                            :loading="form.processing"
                            :disabled="!form.archive || !!fileError"
                        >
                            {{
                                form.deploy && !activeDeployment
                                    ? $t('Upload and deploy')
                                    : $t('Upload build')
                            }}
                        </AppButton>
                    </div>
                </form>
            </AppCard>

            <!-- How to make the archive -->
            <AppCard
                class="lg:col-span-2"
                :title="$t('How to make the archive')"
            >
                <ol class="space-y-4 text-sm text-slate-600">
                    <li
                        v-for="(step, index) in howTo"
                        :key="index"
                        class="flex gap-3"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 ring-1 ring-brand-100"
                            >{{ index + 1 }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <p class="pt-0.5">{{ step.text }}</p>
                            <div
                                v-if="step.command"
                                class="mt-1.5 flex items-center justify-between gap-2 rounded-lg bg-slate-950 py-1.5 pr-1.5 pl-3 font-mono text-[12.5px] text-slate-100"
                            >
                                <code class="min-w-0 break-all">{{
                                    step.command
                                }}</code>
                                <CopyButton
                                    :text="step.command"
                                    dark
                                    class="shrink-0"
                                />
                            </div>
                            <p
                                v-if="step.hint"
                                class="mt-1.5 text-xs text-slate-500"
                            >
                                {{ step.hint }}
                            </p>
                        </div>
                    </li>
                </ol>

                <div
                    class="text-brand-900 mt-5 flex items-start gap-2.5 rounded-lg bg-brand-50/70 px-3 py-2.5 text-xs leading-relaxed ring-1 ring-brand-100"
                >
                    <Lightbulb class="mt-0.5 size-4 shrink-0 text-brand-600" />
                    <p>
                        {{
                            $t(
                                'Want it automatic? Choose "Build locally" when you deploy: AutoDeploy runs the build in your local project folder and uploads it for you.',
                            )
                        }}
                    </p>
                </div>
            </AppCard>
        </div>

        <!-- Saved builds -->
        <AppCard
            class="mt-6"
            :title="$t('Saved builds')"
            :description="
                $t(
                    'Only the newest :count builds are kept; older ones are deleted automatically.',
                    { count: project.keep_builds },
                )
            "
            flush
        >
            <ul v-if="builds.length" class="divide-y divide-slate-100">
                <li
                    v-for="(build, index) in builds"
                    :key="build.id"
                    class="flex flex-col gap-3 px-4 py-4 sm:px-5 md:flex-row md:items-center"
                >
                    <div class="flex min-w-0 flex-1 gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                            :class="
                                build.source === 'local'
                                    ? 'bg-sky-50 text-sky-600'
                                    : 'bg-violet-50 text-violet-600'
                            "
                        >
                            <Laptop
                                v-if="build.source === 'local'"
                                class="size-5"
                            />
                            <FileArchive v-else class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="min-w-0 truncate text-sm font-semibold text-slate-900"
                                    :title="
                                        build.original_name ??
                                        build.display_name
                                    "
                                    >{{ build.display_name }}</span
                                >
                                <span class="text-xs text-slate-400"
                                    >#{{ build.id }}</span
                                >
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1"
                                    :class="
                                        build.source === 'local'
                                            ? 'bg-sky-50 text-sky-700 ring-sky-100'
                                            : 'bg-violet-50 text-violet-700 ring-violet-100'
                                    "
                                    >{{ build.source_label }}</span
                                >
                                <span
                                    v-if="index === 0"
                                    class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 ring-1 ring-emerald-100"
                                    >{{ $t('Latest') }}</span
                                >
                            </div>
                            <p
                                class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500"
                            >
                                <span class="inline-flex items-center gap-1">
                                    <Files class="size-3.5 text-slate-400" />
                                    {{
                                        $t(':count files', {
                                            count: build.file_count,
                                        })
                                    }}
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <HardDrive
                                        class="size-3.5 text-slate-400"
                                    />
                                    {{ formatBytes(build.size) }}
                                </span>
                                <span
                                    v-if="build.short_commit"
                                    class="inline-flex items-center gap-1 font-mono"
                                >
                                    <GitCommitHorizontal
                                        class="size-3.5 text-slate-400"
                                    />
                                    <a
                                        v-if="
                                            project.github_slug &&
                                            build.commit_hash
                                        "
                                        :href="`https://github.com/${project.github_slug}/commit/${build.commit_hash}`"
                                        target="_blank"
                                        rel="noopener"
                                        class="hover:text-brand-600"
                                        >{{ build.short_commit }}</a
                                    >
                                    <span v-else>{{ build.short_commit }}</span>
                                </span>
                                <span
                                    v-if="build.branch"
                                    class="inline-flex items-center gap-1"
                                >
                                    <GitBranch
                                        class="size-3.5 text-slate-400"
                                    />
                                    {{ build.branch }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1"
                                    :title="formatDateTime(build.created_at)"
                                >
                                    <Clock class="size-3.5 text-slate-400" />
                                    {{
                                        build.user
                                            ? $t(':when by :name', {
                                                  when: timeAgo(
                                                      build.created_at,
                                                  ),
                                                  name: build.user,
                                              })
                                            : timeAgo(build.created_at)
                                    }}
                                </span>
                            </p>
                            <p
                                v-if="!build.has_manifest"
                                class="mt-2 inline-flex items-start gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800"
                            >
                                <TriangleAlert
                                    class="mt-px size-3.5 shrink-0"
                                />
                                {{
                                    $t(
                                        'No manifest.json in this build: Laravel will not find the assets. Check that you zipped public/build.',
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="flex shrink-0 flex-wrap items-center gap-2 pl-13 md:pl-0"
                    >
                        <AppButton
                            size="sm"
                            :icon="Rocket"
                            :loading="deployingId === build.id"
                            :disabled="
                                !!activeDeployment || deployingId !== null
                            "
                            @click="deployBuild(build)"
                            >{{ $t('Deploy this build') }}</AppButton
                        >
                        <a
                            :href="downloadUrl(build)"
                            class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white p-2 text-slate-600 shadow-xs transition hover:bg-slate-50 hover:text-slate-900"
                            :title="$t('Download')"
                            download
                        >
                            <Download class="size-4" />
                        </a>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md p-2 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                            :title="$t('Delete')"
                            @click="toDelete = build"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </li>
            </ul>

            <EmptyState
                v-else
                :icon="Boxes"
                :title="$t('No saved builds yet')"
                :description="
                    $t(
                        'Upload a zip of public/build above. Builds made with Build locally are saved here too.',
                    )
                "
            />
        </AppCard>

        <ConfirmModal
            :show="toDelete !== null"
            :title="$t('Delete this build?')"
            :message="
                toDelete
                    ? $t(
                          'Build #:id (:name) is removed from AutoDeploy. Releases already on the server are not affected.',
                          { id: toDelete.id, name: toDelete.display_name },
                      )
                    : ''
            "
            :confirm-label="$t('Delete')"
            :processing="deleting"
            @confirm="destroy"
            @close="toDelete = null"
        />
    </ProjectShell>
</template>

<script lang="ts">
import {
    Boxes,
    Clock,
    Download,
    FileArchive,
    Files,
    GitBranch,
    GitCommitHorizontal,
    HardDrive,
    Laptop,
    Lightbulb,
    Rocket,
    Trash2,
    TriangleAlert,
    Upload,
    UploadCloud,
    X,
} from '@lucide/vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import CopyButton from '@/components/general/CopyButton.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import ToggleInput from '@/components/inputs/ToggleInput.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import projects from '@/routes/projects';
import type { Build, Card, Deployment, Project } from '@/types';
import { formatBytes, formatDateTime, timeAgo } from '@/utils/format';

const EXTENSIONS = ['.zip', '.tar.gz', '.tgz', '.tar'];

/** Saved front-end builds: upload a zip of public/build made on the PC, deploy, download, delete. */
export default defineComponent({
    name: 'ProjectsBuilds',

    components: {
        AppButton,
        AppCard,
        Clock,
        ConfirmModal,
        CopyButton,
        Download,
        EmptyState,
        FileArchive,
        Files,
        GitBranch,
        GitCommitHorizontal,
        HardDrive,
        Head,
        Laptop,
        Lightbulb,
        ProjectShell,
        TextInput,
        ToggleInput,
        Trash2,
        TriangleAlert,
        UploadCloud,
        X,
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
        builds: { type: Array as PropType<Build[]>, required: true },
        maxUploadMb: { type: Number, required: true },
    },

    data() {
        return {
            form: useForm({
                archive: null as File | null,
                label: '',
                commit_hash: '',
                deploy: false,
            }),
            dragging: false,
            fileError: '',
            deployingId: null as number | null,
            toDelete: null as Build | null,
            deleting: false,
            Boxes: markRaw(Boxes),
            Rocket: markRaw(Rocket),
            Upload: markRaw(Upload),
        };
    },

    computed: {
        howTo(): { text: string; command?: string; hint?: string }[] {
            return [
                {
                    text: this.$t('In your project folder, build the assets:'),
                    command: 'npm run build',
                },
                {
                    text: this.$t(
                        'Zip the contents of public/build (PowerShell):',
                    ),
                    command:
                        'Compress-Archive -Path public\\build\\* -DestinationPath build.zip -Force',
                    hint: this.$t(
                        'Zipping the build folder itself, or public/build, works too: AutoDeploy looks for manifest.json.',
                    ),
                },
                {
                    text: this.$t(
                        'Optional: copy the commit it was built from and paste it in Commit:',
                    ),
                    command: 'git rev-parse HEAD',
                },
                {
                    text: this.$t(
                        'Drop build.zip here (up to :size MB) and deploy it now or later.',
                        { size: this.maxUploadMb },
                    ),
                },
            ];
        },

        uploadPercent(): number {
            return Math.round(this.form.progress?.percentage ?? 0);
        },

        dropZoneClass(): string {
            if (this.dragging) {
                return 'border-brand-500 bg-brand-50/70 ring-4 ring-brand-100';
            }

            if (this.form.archive) {
                return 'border-brand-300 bg-brand-50/30';
            }

            if (this.fileError || this.form.errors.archive) {
                return 'border-rose-300 bg-rose-50/30 hover:border-rose-400';
            }

            return 'border-slate-300 bg-slate-50/50 hover:border-brand-400 hover:bg-brand-50/30';
        },
    },

    methods: {
        formatBytes,
        formatDateTime,
        timeAgo,

        downloadUrl(build: Build): string {
            return projects.builds.download([this.project.id, build.id]).url;
        },

        onDragLeave(event: DragEvent): void {
            // Ignore leaving towards a child element of the drop zone.
            const zone = event.currentTarget as HTMLElement;

            if (!zone.contains(event.relatedTarget as Node | null)) {
                this.dragging = false;
            }
        },

        onDrop(event: DragEvent): void {
            this.dragging = false;
            this.setFile(event.dataTransfer?.files?.[0] ?? null);
        },

        onFileChosen(event: Event): void {
            const input = event.target as HTMLInputElement;
            this.setFile(input.files?.[0] ?? null);
        },

        setFile(file: File | null): void {
            this.form.clearErrors('archive');
            this.fileError = '';
            this.form.archive = file;

            if (!file) {
                return;
            }

            const name = file.name.toLowerCase();

            if (!EXTENSIONS.some((extension) => name.endsWith(extension))) {
                this.fileError = this.$t(
                    'Upload a .zip, .tar.gz or .tgz archive.',
                );
            } else if (file.size > this.maxUploadMb * 1024 * 1024) {
                this.fileError = this.$t('The file is larger than :size MB.', {
                    size: this.maxUploadMb,
                });
            }
        },

        clearFile(): void {
            this.setFile(null);

            const input = this.$refs.file as HTMLInputElement | undefined;

            if (input) {
                input.value = '';
            }
        },

        upload(): void {
            if (!this.form.archive || this.fileError) {
                return;
            }

            this.form
                .transform((data) => ({
                    ...data,
                    deploy: data.deploy && !this.activeDeployment,
                }))
                .post(projects.builds.store(this.project.id).url, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        this.form.reset();
                        this.clearFile();
                    },
                });
        },

        deployBuild(build: Build): void {
            router.post(
                projects.deploy(this.project.id).url,
                { build_source: 'upload', build_id: build.id },
                {
                    onStart: () => (this.deployingId = build.id),
                    onFinish: () => (this.deployingId = null),
                },
            );
        },

        destroy(): void {
            if (!this.toDelete) {
                return;
            }

            router.delete(
                projects.builds.destroy([this.project.id, this.toDelete.id])
                    .url,
                {
                    preserveScroll: true,
                    onStart: () => (this.deleting = true),
                    onFinish: () => {
                        this.deleting = false;
                        this.toDelete = null;
                    },
                },
            );
        },
    },
});
</script>
