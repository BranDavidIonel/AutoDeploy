<template>
    <section class="card relative overflow-hidden">
        <!-- Soft brand glow -->
        <div
            class="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-brand-100/70 blur-3xl"
        />
        <div
            class="pointer-events-none absolute -bottom-32 -left-16 size-64 rounded-full bg-violet-100/60 blur-3xl"
        />

        <div class="relative px-5 py-6 sm:px-8 sm:py-8">
            <div class="flex items-start gap-4">
                <span
                    class="hidden size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-violet-600 shadow-md shadow-brand-600/30 sm:flex"
                >
                    <Rocket class="size-6 text-white" />
                </span>
                <div>
                    <h2
                        class="text-lg font-semibold tracking-tight text-slate-900"
                    >
                        {{ $t('Get your first project online') }}
                    </h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        {{
                            $t(
                                'Two things to set up, then every deploy is one click: zero downtime, with rollback.',
                            )
                        }}
                    </p>
                </div>
            </div>

            <ol class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-3">
                <li
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="flex flex-col rounded-xl border bg-white/80 p-4 backdrop-blur transition"
                    :class="
                        step.state === 'current'
                            ? 'border-brand-300 shadow-sm ring-4 ring-brand-100'
                            : 'border-slate-200'
                    "
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                            :class="badgeClass(step.state)"
                        >
                            <Check
                                v-if="step.state === 'done'"
                                class="size-4"
                            />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <h3
                            class="text-sm font-semibold"
                            :class="
                                step.state === 'todo'
                                    ? 'text-slate-500'
                                    : 'text-slate-900'
                            "
                        >
                            {{ step.title }}
                        </h3>
                    </div>
                    <p
                        class="mt-2 flex-1 text-sm leading-relaxed text-slate-500"
                    >
                        {{ step.text }}
                    </p>
                    <div v-if="step.href && step.state !== 'done'" class="mt-4">
                        <AppButton
                            :href="step.href"
                            size="sm"
                            :variant="
                                step.state === 'current'
                                    ? 'primary'
                                    : 'secondary'
                            "
                            :icon="step.icon"
                            >{{ step.action }}</AppButton
                        >
                    </div>
                    <p
                        v-else-if="step.state === 'done'"
                        class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600"
                    >
                        <CircleCheckBig class="size-4" />
                        {{ $t('Done') }}
                    </p>
                </li>
            </ol>
        </div>
    </section>
</template>

<script lang="ts">
import {
    Check,
    CircleCheckBig,
    FolderPlus,
    Rocket,
    ServerCog,
} from '@lucide/vue';
import type { Component } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import projects from '@/routes/projects';
import servers from '@/routes/servers';

type StepState = 'done' | 'current' | 'todo';

type Step = {
    key: string;
    title: string;
    text: string;
    state: StepState;
    action?: string;
    href?: string;
    icon?: Component;
};

/** First-run guide on the dashboard: 1) add a server 2) add a project 3) deploy. */
export default defineComponent({
    name: 'GettingStarted',

    components: { AppButton, Check, CircleCheckBig, Rocket },

    props: {
        hasServers: { type: Boolean, default: false },
        hasProjects: { type: Boolean, default: false },
    },

    computed: {
        steps(): Step[] {
            return [
                {
                    key: 'server',
                    title: this.$t('Add a server'),
                    text: this.$t(
                        'The VPS your Laravel app runs on. AutoDeploy connects to it over SSH, or runs on it directly.',
                    ),
                    state: this.hasServers ? 'done' : 'current',
                    action: this.$t('Add server'),
                    href: servers.create().url,
                    icon: markRaw(ServerCog),
                },
                {
                    key: 'project',
                    title: this.$t('Add a project'),
                    text: this.$t(
                        'Your GitHub repository, the branch, and a new folder on the server for the releases.',
                    ),
                    state: this.hasProjects
                        ? 'done'
                        : this.hasServers
                          ? 'current'
                          : 'todo',
                    action: this.$t('New project'),
                    href: this.hasServers ? projects.create().url : undefined,
                    icon: markRaw(FolderPlus),
                },
                {
                    key: 'deploy',
                    title: this.$t('Run the quick setup and deploy'),
                    text: this.$t(
                        'Prepare the server once, save the .env, then press Deploy. Every next deploy is one click.',
                    ),
                    state: 'todo',
                },
            ];
        },
    },

    methods: {
        badgeClass(state: StepState): string {
            return {
                done: 'bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200',
                current:
                    'bg-brand-600 text-white shadow-sm shadow-brand-600/30',
                todo: 'bg-slate-100 text-slate-400',
            }[state];
        },
    },
});
</script>
