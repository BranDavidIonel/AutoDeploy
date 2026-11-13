<template>
    <Head :title="`${$t('Backups')} · ${project.name}`" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="backups"
        :reload-only="['backups', 'totalSize', 'restoringId']"
    >
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- Backups on the server -->
            <AppCard
                class="xl:col-span-2"
                :title="$t('Database backups on the server')"
                :description="
                    $t(':count backups, :size in total', {
                        count: backups.length,
                        size: formatBytes(totalSize),
                    })
                "
                flush
            >
                <template #actions>
                    <AppButton
                        size="sm"
                        :icon="DatabaseBackup"
                        :loading="backingUp"
                        :disabled="!!activeDeployment"
                        @click="backUpNow"
                        >{{ $t('Back up now') }}</AppButton
                    >
                </template>

                <ul v-if="backups.length" class="divide-y divide-slate-100">
                    <li
                        v-for="backup in backups"
                        :key="backup.id"
                        class="flex flex-col gap-3 px-4 py-4 sm:px-5 md:flex-row md:items-center"
                    >
                        <div class="flex min-w-0 flex-1 gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                                :class="kindStyle(backup).icon"
                            >
                                <component
                                    :is="kindIcon(backup)"
                                    class="size-5"
                                />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="min-w-0 font-mono text-[13px] font-semibold break-all text-slate-900"
                                        :title="backup.file"
                                        >{{ backup.file_name }}</span
                                    >
                                    <span class="text-xs text-slate-400"
                                        >#{{ backup.id }}</span
                                    >
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1"
                                        :class="kindStyle(backup).chip"
                                        >{{ backup.kind_label }}</span
                                    >
                                    <span
                                        v-if="restoringId === backup.id"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-amber-100"
                                    >
                                        <LoaderCircle
                                            class="size-3 animate-spin"
                                        />
                                        {{ $t('Being restored') }}
                                    </span>
                                </div>
                                <p
                                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500"
                                >
                                    <span
                                        class="inline-flex items-center gap-1"
                                        :title="
                                            formatDateTime(backup.created_at)
                                        "
                                    >
                                        <Clock
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{ formatDateTime(backup.created_at) }}
                                        ·
                                        {{ timeAgo(backup.created_at) }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <HardDrive
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{ formatBytes(backup.size) }}
                                    </span>
                                    <span
                                        class="inline-flex min-w-0 items-center gap-1"
                                    >
                                        <Database
                                            class="size-3.5 shrink-0 text-slate-400"
                                        />
                                        <span class="truncate">{{
                                            databaseLabel(backup)
                                        }}</span>
                                    </span>
                                </p>
                                <p
                                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500"
                                >
                                    <span
                                        v-if="backup.release"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <FolderClock
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{ $t('Live release:') }}
                                        <span class="font-mono">{{
                                            backup.release
                                        }}</span>
                                    </span>
                                    <Link
                                        v-if="backup.deployment_id"
                                        :href="
                                            deploymentUrl(backup.deployment_id)
                                        "
                                        class="inline-flex items-center gap-1 hover:text-brand-600"
                                    >
                                        <ScrollText
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{ madeBy(backup) }}
                                    </Link>
                                    <span v-if="backup.note">{{
                                        backup.note
                                    }}</span>
                                </p>
                            </div>
                        </div>

                        <div
                            class="flex shrink-0 flex-wrap items-center gap-2 pl-13 md:pl-0"
                        >
                            <AppButton
                                size="sm"
                                variant="secondary"
                                :icon="ArchiveRestore"
                                :disabled="!!activeDeployment"
                                @click="openRestore(backup)"
                                >{{ $t('Restore') }}</AppButton
                            >
                            <a
                                :href="downloadUrl(backup)"
                                class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white p-2 text-slate-600 shadow-xs transition hover:bg-slate-50 hover:text-slate-900"
                                :title="$t('Download')"
                            >
                                <Download class="size-4" />
                            </a>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md p-2 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-40"
                                :title="$t('Delete')"
                                :disabled="restoringId === backup.id"
                                @click="toDelete = backup"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="DatabaseBackup"
                    :title="$t('No database backups yet')"
                    :description="
                        $t(
                            'Every deploy makes one right before its migrations (when it is on in Settings), or press Back up now.',
                        )
                    "
                >
                    <AppButton
                        variant="secondary"
                        :icon="DatabaseBackup"
                        :loading="backingUp"
                        :disabled="!!activeDeployment"
                        @click="backUpNow"
                        >{{ $t('Back up now') }}</AppButton
                    >
                </EmptyState>
            </AppCard>

            <!-- How it works -->
            <div class="space-y-6">
                <AppCard :title="$t('How database backups work')">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500">
                                {{ $t('Before each deploy') }}
                            </dt>
                            <dd
                                class="text-right font-medium"
                                :class="
                                    beforeDeploy
                                        ? 'text-emerald-700'
                                        : 'text-slate-500'
                                "
                            >
                                {{ beforeDeploy ? $t('On') : $t('Off') }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500">
                                {{ $t('Kept on the server') }}
                            </dt>
                            <dd class="text-right font-medium text-slate-900">
                                {{
                                    $t(':count newest', {
                                        count: Math.max(
                                            1,
                                            project.keep_db_backups,
                                        ),
                                    })
                                }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500">
                                {{ $t('Total size') }}
                            </dt>
                            <dd class="text-right font-medium text-slate-900">
                                {{ formatBytes(totalSize) }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        class="mt-4 overflow-x-auto rounded-lg bg-slate-950 px-4 py-3 font-mono text-[12.5px] leading-relaxed break-all text-slate-300"
                    >
                        {{ project.paths.backups }}/
                    </div>

                    <ul class="mt-4 space-y-2.5 text-xs leading-relaxed">
                        <li class="flex items-start gap-2 text-slate-600">
                            <Rocket
                                class="mt-px size-3.5 shrink-0 text-slate-400"
                            />
                            {{
                                $t(
                                    'A deploy backs up the database right before its migrations. If the backup fails, the deploy stops there and the site is not touched.',
                                )
                            }}
                        </li>
                        <li class="flex items-start gap-2 text-slate-600">
                            <Recycle
                                class="mt-px size-3.5 shrink-0 text-slate-400"
                            />
                            {{
                                $t(
                                    'After each backup only the newest :count (before deploy and manual) stay, plus the last safety backup made before a restore.',
                                    {
                                        count: Math.max(
                                            1,
                                            project.keep_db_backups,
                                        ),
                                    },
                                )
                            }}
                        </li>
                        <li class="flex items-start gap-2 text-slate-600">
                            <HardDrive
                                class="mt-px size-3.5 shrink-0 text-slate-400"
                            />
                            {{
                                $t(
                                    'A backup needs the size of the database plus :mb MB of free disk space, so it never fills the disk of the site.',
                                    { mb: minFreeMb },
                                )
                            }}
                        </li>
                        <li class="flex items-start gap-2 text-slate-600">
                            <Undo2
                                class="mt-px size-3.5 shrink-0 text-slate-400"
                            />
                            {{
                                $t(
                                    'To go back with the code too, use Back to the previous version on the Releases tab: it can restore the matching backup.',
                                )
                            }}
                        </li>
                    </ul>

                    <AppButton
                        :href="settingsUrl"
                        variant="secondary"
                        size="sm"
                        :icon="Cog"
                        class="mt-4"
                        >{{ $t('Change in Settings') }}</AppButton
                    >
                </AppCard>
            </div>
        </div>

        <!-- Restore: the owner types the project name -->
        <AppModal
            :show="toRestore !== null"
            :title="
                $t('Restore backup #:id?', {
                    id: toRestore?.id ?? '',
                })
            "
            size="md"
            @close="closeRestore"
        >
            <div
                v-if="toRestore"
                class="space-y-4 text-sm leading-relaxed text-slate-600"
            >
                <p>
                    {{
                        $t(
                            'The database of :project goes back to :date (:file). The code on the server does not change.',
                            {
                                project: project.name,
                                date: formatDateTime(toRestore.created_at),
                                file: toRestore.file_name,
                            },
                        )
                    }}
                </p>
                <ul
                    class="space-y-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-xs text-rose-900"
                >
                    <li class="flex items-start gap-1.5">
                        <TriangleAlert
                            class="mt-px size-3.5 shrink-0 text-rose-600"
                        />
                        {{
                            $t(
                                'Every table is replaced: data saved after this backup is lost.',
                            )
                        }}
                    </li>
                    <li class="flex items-start gap-1.5">
                        <ShieldCheck
                            class="mt-px size-3.5 shrink-0 text-rose-600"
                        />
                        {{
                            $t(
                                'First a safety backup of the current database is made; if it fails, nothing is restored.',
                            )
                        }}
                    </li>
                    <li class="flex items-start gap-1.5">
                        <Construction
                            class="mt-px size-3.5 shrink-0 text-rose-600"
                        />
                        {{
                            $t(
                                'The site is in maintenance mode (503) for a short while and comes back up at the end, also when the restore fails.',
                            )
                        }}
                    </li>
                </ul>
                <TextInput
                    v-model="restoreForm.confirm_name"
                    :label="$t('Type :name to confirm', { name: project.name })"
                    :placeholder="project.name"
                    :error="restoreForm.errors.confirm_name"
                    autocomplete="off"
                />
            </div>

            <template #footer>
                <AppButton variant="secondary" @click="closeRestore">{{
                    $t('Cancel')
                }}</AppButton>
                <AppButton
                    variant="danger"
                    :icon="ArchiveRestore"
                    :loading="restoreForm.processing"
                    :disabled="!nameMatches"
                    @click="restore"
                    >{{ $t('Restore the database') }}</AppButton
                >
            </template>
        </AppModal>

        <ConfirmModal
            :show="toDelete !== null"
            :title="$t('Delete this backup?')"
            :message="
                toDelete
                    ? $t(
                          'The file :file is deleted from the server. This cannot be undone.',
                          { file: toDelete.file_name },
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
    ArchiveRestore,
    Clock,
    Cog,
    Construction,
    Database,
    DatabaseBackup,
    Download,
    FolderClock,
    HardDrive,
    LoaderCircle,
    Recycle,
    Rocket,
    ScrollText,
    ShieldCheck,
    Trash2,
    TriangleAlert,
    Undo2,
    UserRound,
} from '@lucide/vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import AppModal from '@/components/modals/AppModal.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import deployments from '@/routes/deployments';
import projects from '@/routes/projects';
import type { Backup, Build, Card, Deployment, Project } from '@/types';
import { formatBytes, formatDateTime, timeAgo } from '@/utils/format';

const KIND_STYLES: Record<Backup['kind'], { icon: string; chip: string }> = {
    before_deploy: {
        icon: 'bg-brand-50 text-brand-600',
        chip: 'bg-brand-50 text-brand-700 ring-brand-100',
    },
    manual: {
        icon: 'bg-sky-50 text-sky-600',
        chip: 'bg-sky-50 text-sky-700 ring-sky-100',
    },
    before_restore: {
        icon: 'bg-amber-50 text-amber-600',
        chip: 'bg-amber-50 text-amber-700 ring-amber-100',
    },
};

const KIND_ICONS: Record<Backup['kind'], Component> = {
    before_deploy: markRaw(Rocket),
    manual: markRaw(UserRound),
    before_restore: markRaw(ShieldCheck),
};

/**
 * Database backups kept on the server: back up now (queued, live log), restore (typed
 * project name), download (streamed from the server), delete.
 */
export default defineComponent({
    name: 'ProjectsBackups',

    components: {
        AppButton,
        AppCard,
        AppModal,
        Clock,
        ConfirmModal,
        Construction,
        Database,
        Download,
        EmptyState,
        FolderClock,
        HardDrive,
        Head,
        Link,
        LoaderCircle,
        ProjectShell,
        Recycle,
        Rocket,
        ScrollText,
        ShieldCheck,
        TextInput,
        Trash2,
        TriangleAlert,
        Undo2,
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
        backups: { type: Array as PropType<Backup[]>, required: true },
        totalSize: { type: Number, required: true },
        /** The backup a queued / running restore puts back. */
        restoringId: {
            type: Number as PropType<number | null>,
            default: null,
        },
        minFreeMb: { type: Number, required: true },
    },

    data() {
        return {
            backingUp: false,
            toRestore: null as Backup | null,
            restoreForm: useForm({ confirm_name: '' }),
            toDelete: null as Backup | null,
            deleting: false,
            ArchiveRestore: markRaw(ArchiveRestore),
            Cog: markRaw(Cog),
            DatabaseBackup: markRaw(DatabaseBackup),
        };
    },

    computed: {
        beforeDeploy(): boolean {
            return (
                this.project.backup_before_deploy &&
                this.project.keep_db_backups > 0
            );
        },

        nameMatches(): boolean {
            return this.restoreForm.confirm_name.trim() === this.project.name;
        },

        settingsUrl(): string {
            return projects.edit(this.project.id).url;
        },
    },

    methods: {
        formatBytes,
        formatDateTime,
        timeAgo,

        kindStyle(backup: Backup): { icon: string; chip: string } {
            return KIND_STYLES[backup.kind];
        },

        kindIcon(backup: Backup): Component {
            return KIND_ICONS[backup.kind];
        },

        databaseLabel(backup: Backup): string {
            return backup.database
                ? `${backup.db_connection} · ${backup.database}`
                : backup.db_connection;
        },

        /** "Deploy #12", "Backup #13", "Restore #14", "Rollback #15". */
        madeBy(backup: Backup): string {
            const id = { id: backup.deployment_id ?? '' };

            switch (backup.deployment_type) {
                case 'deploy':
                    return this.$t('Deploy #:id', id);
                case 'rollback':
                    return this.$t('Rollback #:id', id);
                case 'restore':
                    return this.$t('Restore #:id', id);
                default:
                    return this.$t('Backup #:id', id);
            }
        },

        deploymentUrl(id: number): string {
            return deployments.show(id).url;
        },

        downloadUrl(backup: Backup): string {
            return projects.backups.download([this.project.id, backup.id]).url;
        },

        backUpNow(): void {
            router.post(
                projects.backups.store(this.project.id).url,
                {},
                {
                    onStart: () => (this.backingUp = true),
                    onFinish: () => (this.backingUp = false),
                },
            );
        },

        openRestore(backup: Backup): void {
            this.restoreForm.reset();
            this.restoreForm.clearErrors();
            this.toRestore = backup;
        },

        closeRestore(): void {
            this.toRestore = null;
        },

        restore(): void {
            if (!this.toRestore || !this.nameMatches) {
                return;
            }

            this.restoreForm.post(
                projects.backups.restore([this.project.id, this.toRestore.id])
                    .url,
                { onSuccess: () => this.closeRestore() },
            );
        },

        destroy(): void {
            if (!this.toDelete) {
                return;
            }

            router.delete(
                projects.backups.destroy([this.project.id, this.toDelete.id])
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
