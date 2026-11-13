<template>
    <Head :title="`${$t('Releases')} · ${project.name}`" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="releases"
        :reload-only="['releases']"
    >
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- Timeline of the releases kept on the server -->
            <AppCard
                class="xl:col-span-2"
                :title="$t('Releases on the server')"
                :description="
                    $t(':count kept of the last :max', {
                        count: releases.length,
                        max: project.keep_releases,
                    })
                "
                flush
            >
                <template #actions>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :icon="RefreshCw"
                        :loading="syncing"
                        @click="sync"
                        >{{ $t('Sync with server') }}</AppButton
                    >
                </template>

                <ol v-if="releases.length" class="py-2">
                    <li
                        v-for="(release, index) in releases"
                        :key="release.id"
                        class="relative flex gap-4 px-4 py-4 sm:px-5"
                        :class="{ 'bg-emerald-50/50': release.is_live }"
                    >
                        <!-- Rail -->
                        <div class="relative flex w-8 shrink-0 justify-center">
                            <span
                                v-if="index > 0"
                                class="absolute -top-4 h-4 w-px bg-slate-200"
                            />
                            <span
                                v-if="index < releases.length - 1"
                                class="absolute top-8 -bottom-4 w-px bg-slate-200"
                            />
                            <span
                                class="relative flex size-8 items-center justify-center rounded-full ring-1"
                                :class="
                                    release.is_live
                                        ? 'bg-emerald-500 text-white shadow-sm shadow-emerald-500/40 ring-emerald-500'
                                        : 'bg-white text-slate-400 ring-slate-200'
                                "
                            >
                                <Radio v-if="release.is_live" class="size-4" />
                                <FolderClock v-else class="size-4" />
                            </span>
                        </div>

                        <!-- Details -->
                        <div
                            class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="font-mono text-sm font-semibold break-all text-slate-900"
                                        >{{ release.release }}</span
                                    >
                                    <StatusBadge
                                        v-if="release.is_live"
                                        status="live"
                                    />
                                    <span
                                        v-else-if="index === previousIndex"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600"
                                        >{{ $t('Previous') }}</span
                                    >
                                    <span
                                        v-if="index === 0 && !release.is_live"
                                        class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 ring-1 ring-brand-100"
                                        >{{ $t('Newest') }}</span
                                    >
                                    <span
                                        v-if="backupFor(release)"
                                        class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 ring-1 ring-sky-100"
                                        :title="
                                            $t(
                                                'A rollback to this release can also restore its database.',
                                            )
                                        "
                                    >
                                        <DatabaseBackup class="size-3" />
                                        {{ $t('Database backup') }}
                                    </span>
                                </div>

                                <p
                                    class="mt-1.5 flex min-w-0 items-start gap-1.5 text-sm text-slate-700"
                                >
                                    <GitCommitHorizontal
                                        class="mt-0.5 size-4 shrink-0 text-slate-400"
                                    />
                                    <span class="min-w-0">
                                        <a
                                            v-if="commitUrl(release)"
                                            :href="
                                                commitUrl(release) ?? undefined
                                            "
                                            target="_blank"
                                            rel="noopener"
                                            class="font-mono text-xs font-semibold text-brand-600 hover:text-brand-700"
                                            >{{ release.short_commit }}</a
                                        >
                                        <span
                                            v-else
                                            class="font-mono text-xs font-semibold text-slate-500"
                                            >{{
                                                release.short_commit ?? '—'
                                            }}</span
                                        >
                                        <span
                                            v-if="release.commit_message"
                                            class="ml-1.5 break-words"
                                            >{{ release.commit_message }}</span
                                        >
                                    </span>
                                </p>

                                <p
                                    class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500"
                                >
                                    <span
                                        v-if="release.commit_author"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <UserRound
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{ release.commit_author }}
                                    </span>
                                    <span
                                        v-if="release.branch"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <GitBranch
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{ release.branch }}
                                    </span>
                                    <span
                                        v-if="release.build_source_label"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Package
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{ release.build_source_label }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                        :title="
                                            formatDateTime(
                                                release.finished_at ??
                                                    release.created_at,
                                            )
                                        "
                                    >
                                        <Clock
                                            class="size-3.5 text-slate-400"
                                        />
                                        {{
                                            release.user
                                                ? $t(':when by :name', {
                                                      when: timeAgo(
                                                          release.finished_at ??
                                                              release.created_at,
                                                      ),
                                                      name: release.user,
                                                  })
                                                : timeAgo(
                                                      release.finished_at ??
                                                          release.created_at,
                                                  )
                                        }}
                                    </span>
                                </p>
                            </div>

                            <div
                                class="flex shrink-0 flex-wrap items-center gap-2"
                            >
                                <AppButton
                                    :href="deploymentUrl(release.id)"
                                    variant="ghost"
                                    size="sm"
                                    :icon="ScrollText"
                                    >#{{ release.id }}</AppButton
                                >
                                <AppButton
                                    v-if="!release.is_live"
                                    variant="secondary"
                                    size="sm"
                                    :icon="RotateCcw"
                                    :disabled="!!activeDeployment"
                                    @click="target = release"
                                    >{{ $t('Roll back to this') }}</AppButton
                                >
                                <span
                                    v-else
                                    class="inline-flex"
                                    :title="
                                        previousRelease ? '' : noPreviousHint
                                    "
                                >
                                    <AppButton
                                        size="sm"
                                        :icon="Undo2"
                                        :disabled="
                                            !previousRelease ||
                                            !!activeDeployment
                                        "
                                        @click="target = previousRelease"
                                        >{{
                                            $t('Back to the previous version')
                                        }}</AppButton
                                    >
                                </span>
                            </div>
                        </div>
                    </li>
                </ol>

                <EmptyState
                    v-else
                    :icon="History"
                    :title="$t('No releases on the server yet')"
                    :description="
                        $t(
                            'Every successful deploy leaves a release folder here. If you deployed before, press Sync with server.',
                        )
                    "
                >
                    <AppButton
                        variant="secondary"
                        :icon="RefreshCw"
                        :loading="syncing"
                        @click="sync"
                        >{{ $t('Sync with server') }}</AppButton
                    >
                </EmptyState>
            </AppCard>

            <!-- How it works -->
            <div class="space-y-6">
                <AppCard :title="$t('How releases work')">
                    <p class="text-sm leading-relaxed text-slate-600">
                        {{
                            $t(
                                'Every deploy creates a new folder in releases/ and keeps the last :count of them. The current link points to the live one, so a rollback just switches that link back to an older release: instant, nothing is rebuilt.',
                                { count: project.keep_releases },
                            )
                        }}
                    </p>

                    <div
                        class="mt-4 overflow-x-auto rounded-lg bg-slate-950 px-4 py-3 font-mono text-[12.5px] leading-relaxed text-slate-300"
                    >
                        <div class="break-all text-slate-400">
                            {{ project.deploy_path }}/
                        </div>
                        <div class="pl-3">
                            <span class="text-emerald-400">current</span>
                            <span class="text-slate-500"> → </span>
                            <span class="break-all"
                                >releases/{{
                                    project.active_release ?? '…'
                                }}</span
                            >
                        </div>
                        <div class="pl-3">
                            releases/
                            <span class="text-slate-500"
                                >#
                                {{
                                    $t(':count kept', {
                                        count: project.keep_releases,
                                    })
                                }}</span
                            >
                        </div>
                        <div class="pl-3">
                            shared/
                            <span class="text-slate-500"># .env, storage</span>
                        </div>
                    </div>

                    <div
                        class="mt-4 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-amber-600"
                        />
                        <p>
                            {{
                                $t(
                                    'A plain rollback does not revert database migrations. When the database backup of an older release is still on the server, the rollback can restore it too (safety backup first, maintenance mode meanwhile).',
                                )
                            }}
                            <Link
                                :href="backupsUrl"
                                class="font-medium text-amber-800 underline underline-offset-2 hover:text-amber-950"
                                >{{ $t('Backups') }}</Link
                            >
                        </p>
                    </div>

                    <p class="mt-4 text-xs leading-relaxed text-slate-500">
                        {{
                            $t(
                                'The "After going live" commands (queue restart, PHP-FPM reload) run again after a rollback.',
                            )
                        }}
                    </p>
                </AppCard>
            </div>
        </div>

        <RollbackModal
            :show="target !== null"
            :target="target"
            :backup="target ? backupFor(target) : null"
            :processing="rollingBack"
            @confirm="rollback"
            @close="target = null"
        />
    </ProjectShell>
</template>

<script lang="ts">
import {
    Clock,
    DatabaseBackup,
    FolderClock,
    GitBranch,
    GitCommitHorizontal,
    History,
    Package,
    Radio,
    RefreshCw,
    RotateCcw,
    ScrollText,
    TriangleAlert,
    Undo2,
    UserRound,
} from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import EmptyState from '@/components/general/EmptyState.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import RollbackModal from '@/components/modules/projects/RollbackModal.vue';
import deployments from '@/routes/deployments';
import projects from '@/routes/projects';
import type { Backup, Build, Card, Deployment, Project } from '@/types';
import { formatDateTime, timeAgo } from '@/utils/format';

/** The release folders kept on the server, with an instant rollback to any of them (optionally with the database). */
export default defineComponent({
    name: 'ProjectsReleases',

    components: {
        AppButton,
        AppCard,
        Clock,
        DatabaseBackup,
        EmptyState,
        FolderClock,
        GitBranch,
        GitCommitHorizontal,
        Head,
        Link,
        Package,
        ProjectShell,
        Radio,
        RollbackModal,
        StatusBadge,
        TriangleAlert,
        UserRound,
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
        releases: { type: Array as PropType<Deployment[]>, required: true },
        /** Deployment id → the database backup a rollback to that release can put back. */
        rollbackBackups: {
            type: Object as PropType<Record<string, Backup>>,
            default: () => ({}),
        },
    },

    data() {
        return {
            target: null as Deployment | null,
            syncing: false,
            rollingBack: false,
            History: markRaw(History),
            RefreshCw: markRaw(RefreshCw),
            RotateCcw: markRaw(RotateCcw),
            ScrollText: markRaw(ScrollText),
            Undo2: markRaw(Undo2),
        };
    },

    computed: {
        liveIndex(): number {
            return this.releases.findIndex((release) => release.is_live);
        },

        /** The release right before the live one: the usual rollback target. */
        previousIndex(): number {
            return this.liveIndex === -1 ? -1 : this.liveIndex + 1;
        },

        /** The release right before the live one ("Back to the previous version"), if it is still on the server. */
        previousRelease(): Deployment | null {
            return this.previousIndex === -1
                ? null
                : (this.releases[this.previousIndex] ?? null);
        },

        noPreviousHint(): string {
            return this.$t(
                'There is no older release on the server yet: the first deploy has nothing to go back to.',
            );
        },

        backupsUrl(): string {
            return projects.backups.index(this.project.id).url;
        },
    },

    methods: {
        formatDateTime,
        timeAgo,

        backupFor(release: Deployment): Backup | null {
            return this.rollbackBackups[String(release.id)] ?? null;
        },

        deploymentUrl(id: number): string {
            return deployments.show(id).url;
        },

        commitUrl(release: Deployment): string | null {
            return this.project.github_slug && release.commit_hash
                ? `https://github.com/${this.project.github_slug}/commit/${release.commit_hash}`
                : null;
        },

        sync(): void {
            router.post(
                projects.releases.sync(this.project.id).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => (this.syncing = true),
                    onFinish: () => (this.syncing = false),
                },
            );
        },

        rollback(restoreDatabase: boolean): void {
            if (!this.target) {
                return;
            }

            router.post(
                projects.releases.rollback([this.project.id, this.target.id])
                    .url,
                { restore_database: restoreDatabase },
                {
                    onStart: () => (this.rollingBack = true),
                    onFinish: () => {
                        this.rollingBack = false;
                        this.target = null;
                    },
                },
            );
        },
    },
});
</script>
