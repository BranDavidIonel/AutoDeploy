<template>
    <article
        class="group card hover:shadow-brand-900/5 relative flex flex-col transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg"
        :class="large ? 'p-5' : 'p-4'"
    >
        <!-- The whole card opens the project; the "Open site" link sits above it. -->
        <Link
            :href="showUrl"
            class="absolute inset-0 rounded-xl focus-visible:ring-4 focus-visible:ring-brand-200 focus-visible:outline-none"
            :aria-label="project.name"
        />

        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <span
                    class="flex shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-violet-600 font-semibold text-white shadow-sm shadow-brand-600/30"
                    :class="large ? 'size-11 text-lg' : 'size-9 text-sm'"
                >
                    {{ project.name.charAt(0).toUpperCase() }}
                </span>
                <div class="min-w-0">
                    <h3
                        class="truncate font-semibold text-slate-900 transition group-hover:text-brand-700"
                        :class="large ? 'text-base' : 'text-sm'"
                    >
                        {{ project.name }}
                    </h3>
                    <p class="truncate text-xs text-slate-500">
                        {{ project.github_slug ?? project.repository }}
                    </p>
                </div>
            </div>
            <StatusBadge
                class="shrink-0"
                :status="latest ? latest.status : 'unknown'"
                :label="latest ? latest.status_label : $t('Not deployed')"
            />
        </div>

        <dl
            class="grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm"
            :class="large ? 'mt-5' : 'mt-4'"
        >
            <div class="min-w-0">
                <dt
                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    {{ $t('Branch') }}
                </dt>
                <dd class="mt-0.5 flex items-center gap-1.5 text-slate-700">
                    <GitBranch class="size-3.5 shrink-0 text-slate-400" />
                    <span class="truncate">{{ project.branch }}</span>
                </dd>
            </div>
            <div class="min-w-0">
                <dt
                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    {{ $t('Server') }}
                </dt>
                <dd class="mt-0.5 flex items-center gap-1.5 text-slate-700">
                    <ServerIcon class="size-3.5 shrink-0 text-slate-400" />
                    <span class="truncate">{{
                        project.server?.name ?? '—'
                    }}</span>
                </dd>
            </div>
            <div class="min-w-0">
                <dt
                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    {{ $t('Assets') }}
                </dt>
                <dd class="mt-0.5 flex items-center gap-1.5 text-slate-700">
                    <component
                        :is="strategyIcon"
                        class="size-3.5 shrink-0 text-slate-400"
                    />
                    <span class="truncate">{{
                        project.build_strategy_label
                    }}</span>
                </dd>
            </div>
            <div class="min-w-0">
                <dt
                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    {{ $t('Live release') }}
                </dt>
                <dd class="mt-0.5 flex items-center gap-1.5 text-slate-700">
                    <CircleCheckBig
                        class="size-3.5 shrink-0"
                        :class="
                            project.active_release
                                ? 'text-emerald-500'
                                : 'text-slate-300'
                        "
                    />
                    <span
                        class="truncate"
                        :class="
                            project.active_release
                                ? 'font-mono text-[13px]'
                                : 'text-slate-400'
                        "
                        >{{ project.active_release ?? $t('none yet') }}</span
                    >
                </dd>
            </div>
        </dl>

        <!-- Bigger card: what the last deployment was -->
        <div
            v-if="large && latest"
            class="mt-4 flex items-start gap-2.5 rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-slate-100 ring-inset"
        >
            <GitCommitHorizontal
                class="mt-0.5 size-4 shrink-0 text-slate-400"
            />
            <div class="min-w-0 text-xs">
                <p class="truncate font-medium text-slate-700">
                    {{
                        latest.commit_message ||
                        latest.note ||
                        (latest.type === 'rollback'
                            ? $t('Rollback')
                            : $t('Deploy'))
                    }}
                </p>
                <p class="mt-0.5 text-slate-500">
                    <span v-if="latest.short_commit" class="font-mono">{{
                        latest.short_commit
                    }}</span>
                    <span v-if="latest.short_commit"> · </span>
                    {{ timeAgo(latest.created_at) }}
                    <template v-if="latest.duration !== null">
                        · {{ formatDuration(latest.duration) }}</template
                    >
                </p>
            </div>
        </div>

        <div class="flex-1" />

        <footer
            class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 text-xs text-slate-500"
        >
            <span class="flex min-w-0 items-center gap-1.5">
                <Clock class="size-3.5 shrink-0 text-slate-400" />
                <span class="truncate">{{
                    project.last_deployed_at
                        ? $t('Deployed :when', {
                              when: timeAgo(project.last_deployed_at),
                          })
                        : $t('Never deployed')
                }}</span>
                <span
                    v-if="large && project.auto_deploy"
                    class="ml-1 inline-flex shrink-0 items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 font-medium text-brand-700 ring-1 ring-brand-100 ring-inset"
                    :title="$t('Deploy on git push')"
                >
                    <Zap class="size-3" />
                    {{ $t('on push') }}
                </span>
            </span>
            <a
                v-if="project.url"
                :href="project.url"
                target="_blank"
                rel="noopener"
                class="relative inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 font-medium text-brand-600 transition hover:bg-brand-50 hover:text-brand-700"
            >
                {{ $t('Open site') }}
                <ExternalLink class="size-3.5" />
            </a>
        </footer>
    </article>
</template>

<script lang="ts">
import {
    Ban,
    CircleCheckBig,
    Clock,
    Cloud,
    ExternalLink,
    GitBranch,
    GitCommitHorizontal,
    Laptop,
    Server as ServerIcon,
    Upload,
    Zap,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import projects from '@/routes/projects';
import type { Deployment, Project } from '@/types';
import { formatDuration, timeAgo } from '@/utils/format';

const ICONS: Record<string, Component> = {
    local: markRaw(Laptop),
    upload: markRaw(Upload),
    server: markRaw(Cloud),
    none: markRaw(Ban),
};

/** Project tile for the dashboard and the projects list: status, branch, server, assets, live release. */
export default defineComponent({
    name: 'ProjectCard',

    components: {
        CircleCheckBig,
        Clock,
        ExternalLink,
        GitBranch,
        GitCommitHorizontal,
        Link,
        ServerIcon,
        StatusBadge,
        Zap,
    },

    props: {
        project: { type: Object as PropType<Project>, required: true },
        /** Roomier version with the last commit (projects page). */
        large: { type: Boolean, default: false },
    },

    computed: {
        latest(): Deployment | null {
            return this.project.latest_deployment ?? null;
        },

        showUrl(): string {
            return projects.show(this.project.id).url;
        },

        strategyIcon(): Component {
            return ICONS[this.project.build_strategy] ?? ICONS.none;
        },
    },

    methods: {
        formatDuration,
        timeAgo,
    },
});
</script>
