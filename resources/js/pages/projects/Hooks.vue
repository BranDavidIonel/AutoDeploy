<template>
    <Head :title="`${$t('Deploy script')} · ${project.name}`" />

    <ProjectShell
        :project="project"
        :active-deployment="activeDeployment"
        :deploy-options="deployOptions"
        current="hooks"
    >
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- The whole pipeline, in the order it runs -->
            <section class="min-w-0 lg:col-span-2">
                <div class="mb-5">
                    <h2 class="text-base font-semibold text-slate-900">
                        {{ $t('What happens on every deploy') }}
                    </h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{
                            $t(
                                'Grey steps are done by AutoDeploy. Your own commands run in the purple stages, top to bottom.',
                            )
                        }}
                    </p>
                </div>

                <ol>
                    <li
                        v-for="(node, index) in pipeline"
                        :key="node.key"
                        class="relative flex gap-3 sm:gap-4"
                    >
                        <!-- Rail -->
                        <div class="relative flex w-8 shrink-0 justify-center">
                            <span
                                v-if="index < pipeline.length - 1"
                                class="absolute bottom-0 w-px"
                                :class="[
                                    index === 0 ? 'top-3' : 'top-0',
                                    node.type === 'stage'
                                        ? 'bg-violet-200'
                                        : 'bg-slate-200',
                                ]"
                            />
                            <span
                                class="relative mt-1 flex items-center justify-center rounded-full ring-4 ring-slate-50"
                                :class="nodeIconClass(node)"
                            >
                                <component
                                    :is="node.icon"
                                    :class="
                                        node.type === 'stage'
                                            ? 'size-4'
                                            : 'size-3.5'
                                    "
                                />
                            </span>
                        </div>

                        <!-- System step -->
                        <div
                            v-if="node.type === 'system'"
                            class="min-w-0 flex-1 pt-1.5 pb-5"
                        >
                            <p
                                class="flex flex-wrap items-center gap-x-2 text-sm font-medium"
                                :class="
                                    node.tone === 'live'
                                        ? 'text-emerald-700'
                                        : node.muted
                                          ? 'text-slate-400'
                                          : 'text-slate-600'
                                "
                            >
                                {{ node.name }}
                                <span
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-slate-400 uppercase"
                                    >{{ $t('automatic') }}</span
                                >
                            </p>
                            <p
                                v-if="node.note"
                                class="mt-0.5 text-xs [overflow-wrap:anywhere] text-slate-400"
                            >
                                {{ node.note }}
                            </p>
                        </div>

                        <!-- Stage with custom commands -->
                        <div v-else class="min-w-0 flex-1 pb-6">
                            <div
                                class="rounded-xl border border-violet-200/80 bg-linear-to-b from-violet-50/80 to-white shadow-xs"
                            >
                                <header
                                    class="flex flex-wrap items-start justify-between gap-3 px-4 pt-3.5 pb-3"
                                >
                                    <div class="min-w-0">
                                        <h3
                                            class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900"
                                        >
                                            {{ node.stage.label }}
                                            <span
                                                class="rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-semibold text-violet-700"
                                                >{{
                                                    countLabel(node.stage.value)
                                                }}</span
                                            >
                                        </h3>
                                        <p
                                            class="mt-0.5 text-xs leading-relaxed text-slate-500"
                                        >
                                            {{ node.stage.description }}
                                        </p>
                                    </div>
                                    <AppButton
                                        size="sm"
                                        variant="secondary"
                                        :icon="Plus"
                                        @click="addTo = node.stage"
                                        >{{ $t('Add command') }}</AppButton
                                    >
                                </header>

                                <div
                                    class="space-y-3 px-3 pb-3 sm:px-4 sm:pb-4"
                                >
                                    <div
                                        v-for="(hook, position) in hooksIn(
                                            node.stage.value,
                                        )"
                                        :id="`hook-${hook.id}`"
                                        :key="hook.id"
                                    >
                                        <HookCard
                                            :hook="hook"
                                            :project-id="project.id"
                                            :stages="stages"
                                            :position="position + 1"
                                            :first="position === 0"
                                            :last="
                                                position ===
                                                hooksIn(node.stage.value)
                                                    .length -
                                                    1
                                            "
                                            :moving="moving"
                                            @move="move(hook, $event)"
                                            @delete="toDelete = $event"
                                        />
                                    </div>

                                    <button
                                        v-if="!hooksIn(node.stage.value).length"
                                        type="button"
                                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-violet-200 bg-white/60 px-4 py-4 text-sm text-slate-500 transition hover:border-violet-400 hover:text-violet-700"
                                        @click="addTo = node.stage"
                                    >
                                        <Plus class="size-4" />
                                        {{
                                            $t('No commands here yet. Add one')
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>
            </section>

            <!-- Variables and rules -->
            <aside class="min-w-0">
                <div class="space-y-6 lg:sticky lg:top-6">
                    <AppCard :title="$t('Variables you can use')">
                        <ul class="-my-1 divide-y divide-slate-100">
                            <li
                                v-for="variable in variables"
                                :key="variable.name"
                                class="flex items-center gap-2 py-2"
                            >
                                <div class="min-w-0 flex-1">
                                    <code
                                        class="font-mono text-[13px] font-semibold text-violet-700"
                                        >{{ variable.name }}</code
                                    >
                                    <p
                                        v-if="variable.description"
                                        class="truncate text-xs text-slate-500"
                                        :title="variable.description"
                                    >
                                        {{ variable.description }}
                                    </p>
                                </div>
                                <CopyButton
                                    :text="variable.name"
                                    class="shrink-0"
                                />
                            </li>
                        </ul>
                    </AppCard>

                    <AppCard :title="$t('How the commands run')">
                        <ul class="space-y-3 text-sm text-slate-600">
                            <li class="flex gap-2.5">
                                <FolderOpen
                                    class="mt-0.5 size-4 shrink-0 text-slate-400"
                                />
                                <span>{{
                                    $t(
                                        'Each command is a small bash script that runs inside the new release folder ($RELEASE_PATH).',
                                    )
                                }}</span>
                            </li>
                            <li class="flex gap-2.5">
                                <ShieldCheck
                                    class="mt-0.5 size-4 shrink-0 text-emerald-500"
                                />
                                <span>
                                    {{ $t('Scripts run with') }}
                                    <code class="code-inline">set -e</code>:
                                    {{
                                        $t(
                                            'the first failing line stops the deploy. Before the switch, the live site stays untouched.',
                                        )
                                    }}
                                </span>
                            </li>
                            <li class="flex gap-2.5">
                                <RotateCcw
                                    class="mt-0.5 size-4 shrink-0 text-amber-500"
                                />
                                <span>{{
                                    $t(
                                        '"After going live" commands run when the new release is already live, and again after a rollback.',
                                    )
                                }}</span>
                            </li>
                            <li class="flex gap-2.5">
                                <ToggleRight
                                    class="mt-0.5 size-4 shrink-0 text-slate-400"
                                />
                                <span>{{
                                    $t(
                                        'Turn a command off to skip it without deleting it. Ctrl+S saves the command you are editing.',
                                    )
                                }}</span>
                            </li>
                        </ul>
                    </AppCard>
                </div>
            </aside>
        </div>

        <HookPresetModal
            :show="addTo !== null"
            :stage="addTo"
            :stages="stages"
            :presets="presets"
            :existing="existingNames"
            :processing="adding"
            @choose="add"
            @close="addTo = null"
        />

        <ConfirmModal
            :show="toDelete !== null"
            :title="$t('Delete this command?')"
            :message="
                toDelete
                    ? $t('“:name” will no longer run on deploy.', {
                          name: toDelete.name,
                      })
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
    ArrowRightLeft,
    Broom,
    FolderOpen,
    GitBranch,
    Globe,
    Hammer,
    HeartPulse,
    Laptop,
    Link,
    Package,
    Plus,
    RotateCcw,
    Server,
    ShieldCheck,
    SquareTerminal,
    ToggleRight,
    UserCheck,
} from '@lucide/vue';
import { Head, router } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import CopyButton from '@/components/general/CopyButton.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import HookCard from '@/components/modules/projects/HookCard.vue';
import HookPresetModal from '@/components/modules/projects/HookPresetModal.vue';
import ProjectShell from '@/components/modules/projects/ProjectShell.vue';
import projects from '@/routes/projects';
import type {
    Build,
    Card,
    Deployment,
    Hook,
    HookPreset,
    Project,
} from '@/types';

type SystemNode = {
    type: 'system';
    key: string;
    name: string;
    note?: string;
    icon: Component;
    muted?: boolean;
    tone?: 'live';
};

type StageNode = {
    type: 'stage';
    key: string;
    stage: Card;
    icon: Component;
};

type PipelineNode = SystemNode | StageNode;

const ICONS = {
    laptop: markRaw(Laptop),
    server: markRaw(Server),
    clone: markRaw(GitBranch),
    link: markRaw(Link),
    composer: markRaw(Package),
    build: markRaw(Hammer),
    activate: markRaw(ArrowRightLeft),
    webServer: markRaw(Globe),
    cleanup: markRaw(Broom),
    permissions: markRaw(UserCheck),
    health: markRaw(HeartPulse),
    stage: markRaw(SquareTerminal),
};

/** "Deploy script": the whole pipeline, with the custom commands of each stage editable in place. */
export default defineComponent({
    name: 'ProjectsHooks',

    components: {
        AppButton,
        AppCard,
        ConfirmModal,
        CopyButton,
        FolderOpen,
        Head,
        HookCard,
        HookPresetModal,
        Plus,
        ProjectShell,
        RotateCcw,
        ShieldCheck,
        ToggleRight,
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
        hooks: { type: Array as PropType<Hook[]>, required: true },
        stages: { type: Array as PropType<Card[]>, required: true },
        presets: { type: Array as PropType<HookPreset[]>, required: true },
        variables: {
            type: Array as PropType<{ name: string; description: string }[]>,
            required: true,
        },
    },

    data() {
        return {
            /** Hook ids in the order they run (changed right away when moving, then saved). */
            order: [] as number[],
            moving: false,
            addTo: null as Card | null,
            adding: false,
            /** Ids before adding a command, to scroll to the new one when it arrives. */
            knownIds: null as number[] | null,
            toDelete: null as Hook | null,
            deleting: false,
            Plus: markRaw(Plus),
        };
    },

    computed: {
        orderedHooks(): Hook[] {
            const byId = new Map(this.hooks.map((hook) => [hook.id, hook]));
            const ordered = this.order
                .map((id) => byId.get(id))
                .filter((hook): hook is Hook => hook !== undefined);

            // Anything the local order does not know yet goes last.
            return [
                ...ordered,
                ...this.hooks.filter((hook) => !this.order.includes(hook.id)),
            ];
        },

        existingNames(): string[] {
            return this.hooks.map((hook) => hook.name);
        },

        pipeline(): PipelineNode[] {
            const p = this.project;
            const stage = (value: string): StageNode | null => {
                const card = this.stages.find((s) => s.value === value);

                return card
                    ? {
                          type: 'stage',
                          key: `stage-${value}`,
                          stage: card,
                          icon: ICONS.stage,
                      }
                    : null;
            };
            const hasShared =
                p.shared_dirs.length > 0 || p.shared_files.length > 0;
            // The hooks after going live run as the SSH user; the owner is fixed again after them.
            const hasPermissionsStep =
                !!p.web_user &&
                this.hooksIn('after_activate').some((hook) => hook.enabled);
            // Same rule as DeploymentPlanner: the setting and a site URL.
            const managesWebServer = p.manage_web_server && !!p.url;

            const nodes: (PipelineNode | null)[] = [
                {
                    type: 'system',
                    key: 'local_build',
                    name: this.$t('Build assets locally'),
                    note: this.$t(
                        'Only with “Build locally”: npm run build on this computer.',
                    ),
                    icon: ICONS.laptop,
                    muted: p.build_strategy !== 'local',
                },
                {
                    type: 'system',
                    key: 'prepare',
                    name: this.$t('Prepare server'),
                    note: this.$t('Creates the new release folder in :path', {
                        path: `${p.paths.releases}/`,
                    }),
                    icon: ICONS.server,
                },
                {
                    type: 'system',
                    key: 'clone',
                    name: this.$t('Clone repository'),
                    note: `${p.branch} · ${p.github_slug ?? p.repository}`,
                    icon: ICONS.clone,
                },
                {
                    type: 'system',
                    key: 'shared',
                    name: this.$t('Link shared files'),
                    note: hasShared
                        ? [...p.shared_files, ...p.shared_dirs].join(', ')
                        : this.$t('Nothing to link (set in Settings)'),
                    icon: ICONS.link,
                    muted: !hasShared,
                },
                {
                    type: 'system',
                    key: 'composer',
                    name: this.$t('Install Composer dependencies'),
                    note: p.run_composer
                        ? `composer install ${p.composer_flags}`
                        : this.$t('Turned off in Settings'),
                    icon: ICONS.composer,
                    muted: !p.run_composer,
                },
                stage('before_build'),
                {
                    type: 'system',
                    key: 'assets',
                    name: this.$t('Build / upload assets'),
                    note: this.$t(
                        'npm run build on the server, or the build made locally / a saved build is uploaded. Default: :strategy',
                        { strategy: p.build_strategy_label },
                    ),
                    icon: ICONS.build,
                    muted: p.build_strategy === 'none',
                },
                stage('before_activate'),
                {
                    type: 'system',
                    key: 'activate',
                    name: this.$t('Activate release (switch current)'),
                    note: this.$t(
                        'current now points to the new release: the site runs the new code.',
                    ),
                    icon: ICONS.activate,
                    tone: 'live',
                },
                {
                    type: 'system',
                    key: 'web_server',
                    name: this.$t('Point the web server to current'),
                    note: managesWebServer
                        ? this.$t(
                              'Points the Apache / Nginx site of the site URL to :path (backup, config test, reload). Changes nothing once it serves current.',
                              { path: p.paths.public },
                          )
                        : this.$t(
                              'Off: turn it on in Settings to switch the Apache / Nginx site automatically.',
                          ),
                    icon: ICONS.webServer,
                    muted: !managesWebServer,
                },
                stage('after_activate'),
                {
                    type: 'system',
                    key: 'permissions',
                    name: this.$t('Fix storage permissions'),
                    note: hasPermissionsStep
                        ? this.$t(
                              'Gives storage and bootstrap/cache back to :user after the commands above.',
                              { user: p.web_user ?? '' },
                          )
                        : this.$t(
                              'Only with a web user (Settings) and “After going live” commands.',
                          ),
                    icon: ICONS.permissions,
                    muted: !hasPermissionsStep,
                },
                {
                    type: 'system',
                    key: 'cleanup',
                    name: this.$t('Clean up old releases'),
                    note: this.$t(
                        'Keeps the last :count releases for rollback.',
                        {
                            count: p.keep_releases,
                        },
                    ),
                    icon: ICONS.cleanup,
                },
                p.health_check_url
                    ? {
                          type: 'system',
                          key: 'health',
                          name: this.$t('Health check'),
                          note: p.health_check_url,
                          icon: ICONS.health,
                      }
                    : null,
            ];

            return nodes.filter((node): node is PipelineNode => node !== null);
        },
    },

    watch: {
        hooks: {
            immediate: true,
            handler(hooks: Hook[]) {
                this.order = hooks.map((hook) => hook.id);

                if (this.knownIds) {
                    const added = hooks.find(
                        (hook) => !this.knownIds?.includes(hook.id),
                    );
                    this.knownIds = null;

                    if (added) {
                        this.$nextTick(() => this.reveal(added.id));
                    }
                }
            },
        },
    },

    methods: {
        hooksIn(stage: string): Hook[] {
            return this.orderedHooks.filter((hook) => hook.stage === stage);
        },

        countLabel(stage: string): string {
            const count = this.hooksIn(stage).length;

            return count === 1
                ? this.$t('1 command')
                : this.$t(':count commands', { count });
        },

        nodeIconClass(node: PipelineNode): string {
            if (node.type === 'stage') {
                return 'size-8 bg-violet-600 text-white shadow-sm shadow-violet-600/30';
            }

            if (node.tone === 'live') {
                return 'size-7 bg-emerald-500 text-white shadow-sm shadow-emerald-500/30';
            }

            return node.muted
                ? 'size-7 bg-slate-100 text-slate-300'
                : 'size-7 bg-slate-200 text-slate-500';
        },

        /** Moves a command up (-1) or down (+1) inside its stage and saves the new order. */
        move(hook: Hook, direction: number): void {
            const list = this.hooksIn(hook.stage);
            const from = list.findIndex((item) => item.id === hook.id);
            const to = from + direction;

            if (from === -1 || to < 0 || to >= list.length) {
                return;
            }

            [list[from], list[to]] = [list[to], list[from]];

            const previous = [...this.order];
            const stageValues = this.stages.map((stage) => stage.value);
            const ids = [
                ...stageValues.flatMap((value) =>
                    value === hook.stage
                        ? list.map((item) => item.id)
                        : this.hooksIn(value).map((item) => item.id),
                ),
                // Hooks of an unknown stage keep their place at the end.
                ...this.orderedHooks
                    .filter((item) => !stageValues.includes(item.stage))
                    .map((item) => item.id),
            ];

            this.order = ids;

            router.post(
                projects.hooks.reorder(this.project.id).url,
                { ids },
                {
                    preserveScroll: true,
                    onStart: () => (this.moving = true),
                    onFinish: () => (this.moving = false),
                    onError: () => (this.order = previous),
                },
            );
        },

        add(preset: HookPreset): void {
            if (!this.addTo) {
                return;
            }

            this.knownIds = this.hooks.map((hook) => hook.id);

            router.post(
                projects.hooks.store(this.project.id).url,
                {
                    name: preset.name,
                    stage: this.addTo.value,
                    script: preset.script,
                    enabled: true,
                },
                {
                    preserveScroll: true,
                    onStart: () => (this.adding = true),
                    onSuccess: () => (this.addTo = null),
                    onError: () => (this.knownIds = null),
                    onFinish: () => (this.adding = false),
                },
            );
        },

        /** Scrolls to a command just added and puts the cursor in its script. */
        reveal(id: number): void {
            const element = document.getElementById(`hook-${id}`);

            if (!element) {
                return;
            }

            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const editor = element.querySelector('textarea');

            if (editor) {
                editor.focus({ preventScroll: true });
                editor.setSelectionRange(
                    editor.value.length,
                    editor.value.length,
                );
            }
        },

        destroy(): void {
            if (!this.toDelete) {
                return;
            }

            router.delete(
                projects.hooks.destroy([this.project.id, this.toDelete.id]).url,
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
