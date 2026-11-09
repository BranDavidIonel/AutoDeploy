<template>
    <div>
        <!-- Score -->
        <div class="border-b border-slate-100 px-4 py-4 sm:px-5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <p class="text-sm text-slate-600">
                    <span
                        class="text-2xl font-semibold tracking-tight text-slate-900 tabular-nums"
                        >{{ fine }}</span
                    >
                    <span class="text-slate-400"> / {{ rows.length }}</span>
                    <span class="ml-2">{{
                        counts.fail
                            ? $t('fix the red items before the first deploy')
                            : counts.warn
                              ? $t('ready to deploy, a few things to look at')
                              : $t('everything looks good')
                    }}</span>
                </p>
                <p class="flex items-center gap-3 text-xs font-medium">
                    <span
                        v-if="counts.fail"
                        class="inline-flex items-center gap-1 text-rose-600"
                    >
                        <CircleX class="size-3.5" /> {{ counts.fail }}
                    </span>
                    <span
                        v-if="counts.warn"
                        class="inline-flex items-center gap-1 text-amber-600"
                    >
                        <TriangleAlert class="size-3.5" /> {{ counts.warn }}
                    </span>
                    <span
                        class="inline-flex items-center gap-1 text-emerald-600"
                    >
                        <CircleCheck class="size-3.5" /> {{ fine }}
                    </span>
                </p>
            </div>
            <div
                class="mt-3 flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-slate-100"
            >
                <div
                    class="bg-emerald-500 transition-all"
                    :style="{ width: share(counts.ok + counts.later) }"
                />
                <div
                    class="bg-slate-300 transition-all"
                    :style="{ width: share(counts.skip) }"
                />
                <div
                    class="bg-amber-400 transition-all"
                    :style="{ width: share(counts.warn) }"
                />
                <div
                    class="bg-rose-500 transition-all"
                    :style="{ width: share(counts.fail) }"
                />
            </div>
        </div>

        <!-- Groups -->
        <div v-for="group in groups" :key="group.key">
            <h3
                class="border-b border-slate-100 bg-slate-50/80 px-4 py-2 text-xs font-semibold tracking-wide text-slate-500 uppercase sm:px-5"
            >
                {{ group.title }}
            </h3>
            <ul class="divide-y divide-slate-100">
                <li
                    v-for="row in group.rows"
                    :key="row.key"
                    class="flex gap-3 px-4 py-3 sm:px-5"
                >
                    <span
                        class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full"
                        :class="STATUS_CLASS[row.status]"
                    >
                        <Check v-if="row.status === 'ok'" class="size-3.5" />
                        <Minus
                            v-else-if="row.status === 'skip'"
                            class="size-3.5"
                        />
                        <Clock
                            v-else-if="row.status === 'later'"
                            class="size-3.5"
                        />
                        <X v-else-if="row.status === 'fail'" class="size-3.5" />
                        <span v-else class="text-xs font-bold">!</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-col gap-0.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3"
                        >
                            <p class="text-sm font-medium text-slate-900">
                                {{ row.label }}
                            </p>
                            <p
                                v-if="row.value"
                                class="min-w-0 font-mono text-xs break-all text-slate-500 sm:text-right"
                            >
                                {{ row.value }}
                            </p>
                        </div>
                        <p
                            v-if="row.fix"
                            class="mt-0.5 text-xs leading-relaxed"
                            :class="FIX_CLASS[row.status]"
                        >
                            {{ row.fix }}
                            <template v-if="row.link">
                                <a
                                    v-if="row.link.anchor"
                                    :href="row.link.href"
                                    class="font-semibold whitespace-nowrap underline-offset-2 hover:underline"
                                    >{{ row.link.label }} →</a
                                >
                                <Link
                                    v-else
                                    :href="row.link.href"
                                    class="font-semibold whitespace-nowrap underline-offset-2 hover:underline"
                                    >{{ row.link.label }} →</Link
                                >
                            </template>
                        </p>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<script lang="ts">
import {
    Check,
    CircleCheck,
    CircleX,
    Clock,
    Minus,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import projects from '@/routes/projects';
import servers from '@/routes/servers';
import type { Project } from '@/types';

/** ok = fine, warn = look at it, fail = blocks a deploy, skip = not needed, later = done by the first deploy. */
type CheckStatus = 'ok' | 'warn' | 'fail' | 'skip' | 'later';

type CheckRow = {
    key: string;
    label: string;
    status: CheckStatus;
    value?: string;
    fix?: string;
    link?: { label: string; href: string; anchor?: boolean };
};

const GROUPS: { key: 'files' | 'software' | 'access'; keys: string[] }[] = [
    {
        key: 'files',
        keys: [
            'deploy_path',
            'structure',
            'env',
            'app_key',
            'storage',
            'current',
        ],
    },
    {
        key: 'software',
        keys: ['php', 'composer', 'git', 'node', 'supervisor'],
    },
    {
        key: 'access',
        keys: ['ssh_key', 'repo', 'web_user', 'web_root'],
    },
];

/** Readable checklist of the key=value lines printed by ProjectSetup::checkScript(). */
export default defineComponent({
    name: 'SetupChecklist',

    components: {
        Check,
        CircleCheck,
        CircleX,
        Clock,
        Link,
        Minus,
        TriangleAlert,
        X,
    },

    props: {
        project: { type: Object as PropType<Project>, required: true },
        checks: {
            type: Object as PropType<Record<string, string>>,
            required: true,
        },
    },

    data() {
        return {
            STATUS_CLASS: {
                ok: 'bg-emerald-100 text-emerald-700',
                warn: 'bg-amber-100 text-amber-700',
                fail: 'bg-rose-100 text-rose-700',
                skip: 'bg-slate-100 text-slate-400',
                later: 'bg-sky-100 text-sky-700',
            } as Record<CheckStatus, string>,
            FIX_CLASS: {
                ok: 'text-slate-500',
                warn: 'text-amber-800',
                fail: 'text-rose-700',
                skip: 'text-slate-500',
                later: 'text-sky-800',
            } as Record<CheckStatus, string>,
        };
    },

    computed: {
        rows(): CheckRow[] {
            // web_server only says which server the web_root row is about.
            return Object.keys(this.checks)
                .filter((key) => key !== 'web_server')
                .map((key) => this.evaluate(key, this.checks[key]));
        },

        groups(): { key: string; title: string; rows: CheckRow[] }[] {
            const titles = {
                files: this.$t('Folders and files'),
                software: this.$t('Software on the server'),
                access: this.$t('Access and web server'),
            };
            const known = GROUPS.flatMap((group) => group.keys);
            const groups = GROUPS.map((group) => ({
                key: group.key as string,
                title: titles[group.key],
                rows: group.keys
                    .filter((key) => key in this.checks)
                    .map((key) => this.evaluate(key, this.checks[key])),
            }));
            const other = this.rows.filter((row) => !known.includes(row.key));

            if (other.length) {
                groups.push({
                    key: 'other',
                    title: this.$t('Other'),
                    rows: other,
                });
            }

            return groups.filter((group) => group.rows.length > 0);
        },

        counts(): Record<CheckStatus, number> {
            const counts: Record<CheckStatus, number> = {
                ok: 0,
                warn: 0,
                fail: 0,
                skip: 0,
                later: 0,
            };

            this.rows.forEach((row) => counts[row.status]++);

            return counts;
        },

        /** "Not needed" and "done by the first deploy" count as fine. */
        fine(): number {
            return this.counts.ok + this.counts.skip + this.counts.later;
        },
    },

    methods: {
        share(count: number): string {
            return this.rows.length
                ? `${(count / this.rows.length) * 100}%`
                : '0%';
        },

        evaluate(key: string, raw: string): CheckRow {
            const p = this.project;
            const t = (
                text: string,
                replacements?: Record<string, string | number>,
            ) => this.$t(text, replacements);
            const yes = raw === 'yes';
            const id = p.id;
            const environment = {
                label: t('Environment'),
                href: projects.environment.edit(id).url,
            };
            const settings = {
                label: t('Settings'),
                href: projects.edit(id).url,
            };

            switch (key) {
                case 'deploy_path':
                    return yes
                        ? {
                              key,
                              label: t('Deploy folder'),
                              status: 'ok',
                              value: p.deploy_path,
                          }
                        : {
                              key,
                              label: t('Deploy folder'),
                              status: 'warn',
                              value: p.deploy_path,
                              fix: t(
                                  'Does not exist yet: quick setup creates it.',
                              ),
                          };

                case 'structure':
                    return yes
                        ? {
                              key,
                              label: t('releases/ and shared/ folders'),
                              status: 'ok',
                          }
                        : {
                              key,
                              label: t('releases/ and shared/ folders'),
                              status: 'warn',
                              fix: t('Missing: quick setup creates both.'),
                          };

                case 'env':
                    return yes
                        ? {
                              key,
                              label: t('shared/.env'),
                              status: 'ok',
                              value: t('present'),
                          }
                        : {
                              key,
                              label: t('shared/.env'),
                              status: 'fail',
                              value: t('missing'),
                              fix: t(
                                  'Paste it in the Environment tab, or import it with quick setup.',
                              ),
                              link: environment,
                          };

                case 'app_key':
                    return yes
                        ? {
                              key,
                              label: t('APP_KEY in .env'),
                              status: 'ok',
                              value: t('set'),
                          }
                        : {
                              key,
                              label: t('APP_KEY in .env'),
                              status: 'fail',
                              value: t('empty'),
                              fix: t(
                                  'Laravel does not start without it: press Generate APP_KEY in the Environment tab.',
                              ),
                              link: environment,
                          };

                case 'storage':
                    if (
                        !p.shared_dirs.some(
                            (dir) =>
                                dir.replace(/^\/+|\/+$/g, '') === 'storage',
                        )
                    ) {
                        return {
                            key,
                            label: t('shared/storage'),
                            status: 'skip',
                            fix: t(
                                'storage is not a shared folder of this project.',
                            ),
                        };
                    }

                    return yes
                        ? {
                              key,
                              label: t('shared/storage'),
                              status: 'ok',
                              value: t('present'),
                          }
                        : {
                              key,
                              label: t('shared/storage'),
                              status: 'warn',
                              value: t('missing'),
                              fix: t(
                                  'Quick setup creates the Laravel storage folders (or imports them from the old site).',
                              ),
                          };

                case 'current':
                    if (raw === 'none') {
                        return {
                            key,
                            label: t('current link'),
                            status: 'later',
                            value: t('none yet'),
                            fix: t('Created by the first deploy.'),
                        };
                    }

                    if (raw === 'not_a_link') {
                        return {
                            key,
                            label: t('current link'),
                            status: 'fail',
                            value: t('a real folder'),
                            fix: t(
                                'current must be a link that AutoDeploy switches. Move the folder away first, e.g. mv current current.old',
                            ),
                        };
                    }

                    return {
                        key,
                        label: t('current link'),
                        status: 'ok',
                        value: `→ releases/${raw}`,
                    };

                case 'git':
                    return yes
                        ? {
                              key,
                              label: 'Git',
                              status: 'ok',
                              value: t('installed'),
                          }
                        : {
                              key,
                              label: 'Git',
                              status: 'fail',
                              value: t('not found'),
                              fix: t('Install it: sudo apt install git'),
                          };

                case 'composer':
                    if (yes) {
                        return {
                            key,
                            label: 'Composer',
                            status: 'ok',
                            value: p.composer_binary,
                        };
                    }

                    return p.run_composer
                        ? {
                              key,
                              label: 'Composer',
                              status: 'fail',
                              value: t('not found'),
                              fix: t(
                                  'Install Composer (getcomposer.org) or set its path in Settings.',
                              ),
                              link: settings,
                          }
                        : {
                              key,
                              label: 'Composer',
                              status: 'skip',
                              value: t('not found'),
                              fix: t(
                                  'Not needed: composer install is turned off for this project.',
                              ),
                          };

                case 'php':
                    return raw && raw !== 'no'
                        ? {
                              key,
                              label: 'PHP',
                              status: 'ok',
                              value: `${raw} · ${p.php_binary}`,
                          }
                        : {
                              key,
                              label: 'PHP',
                              status: 'fail',
                              value: t('not found'),
                              fix: t(
                                  '":binary" does not run on the server. Set the right PHP binary in Settings (e.g. php8.4).',
                                  { binary: p.php_binary },
                              ),
                              link: settings,
                          };

                case 'node':
                    if (raw && raw !== 'no') {
                        return {
                            key,
                            label: 'Node.js',
                            status: 'ok',
                            value: raw,
                        };
                    }

                    return p.build_strategy === 'server'
                        ? {
                              key,
                              label: 'Node.js',
                              status: 'fail',
                              value: t('not found'),
                              fix: t(
                                  'Building on the server needs Node.js. Install it, or build locally / upload the build instead.',
                              ),
                          }
                        : {
                              key,
                              label: 'Node.js',
                              status: 'skip',
                              value: t('not installed'),
                              fix: t(
                                  'That is fine: the assets are built on your PC and uploaded.',
                              ),
                          };

                case 'supervisor':
                    return yes
                        ? {
                              key,
                              label: 'Supervisor',
                              status: 'ok',
                              value: t('installed'),
                          }
                        : {
                              key,
                              label: 'Supervisor',
                              status: 'warn',
                              value: t('not installed'),
                              fix: t(
                                  'Needed only for queue workers: Quick setup on the Workers tab installs it.',
                              ),
                              link: {
                                  label: t('Workers'),
                                  href: projects.workers.index(id).url,
                              },
                          };

                case 'web_user':
                    if (raw === 'none') {
                        return {
                            key,
                            label: t('Web user'),
                            status: 'warn',
                            value: t('not set'),
                            fix: t(
                                'Without it the owner of storage is not fixed. Set it in Settings (usually www-data).',
                            ),
                            link: settings,
                        };
                    }

                    return yes
                        ? {
                              key,
                              label: t('Web user'),
                              status: 'ok',
                              value: p.web_user ?? '',
                          }
                        : {
                              key,
                              label: t('Web user'),
                              status: 'fail',
                              value: p.web_user ?? '',
                              fix: t(
                                  'This user does not exist on the server. Check the web user in Settings.',
                              ),
                              link: settings,
                          };

                case 'web_root': {
                    const apache = this.checks.web_server === 'apache';
                    const server = apache ? 'Apache' : 'Nginx';
                    // The deploys switch the site themselves (same rule as the planner).
                    const auto = p.manage_web_server && !!p.url;

                    if (yes) {
                        return {
                            key,
                            label: t(':server site', { server }),
                            status: 'ok',
                            value: p.paths.public,
                        };
                    }

                    return raw === 'missing'
                        ? {
                              key,
                              label: t('Web server'),
                              status: 'warn',
                              value: t('Apache / Nginx not found'),
                              fix: t(
                                  'Using another web server? Point its document root to :path.',
                                  { path: p.paths.public },
                              ),
                          }
                        : {
                              key,
                              label: t(':server site', { server }),
                              status: 'warn',
                              value: t('not pointing here yet'),
                              fix: auto
                                  ? t(
                                        'The next deploy points it here: “Point the web server to current” is on.',
                                    )
                                  : apache
                                    ? t(
                                          'Set DocumentRoot to :path in the Apache site (and in its -le-ssl.conf), then reload Apache.',
                                          { path: p.paths.public },
                                      )
                                    : t(
                                          'Set root to :path in your Nginx site, then reload Nginx.',
                                          { path: p.paths.public },
                                      ),
                              link: {
                                  label: t(':server config', { server }),
                                  href: '#web-server',
                                  anchor: true,
                              },
                          };
                }

                case 'repo':
                    return yes
                        ? {
                              key,
                              label: t('Repository access'),
                              status: 'ok',
                              value: p.branch,
                          }
                        : {
                              key,
                              label: t('Repository access'),
                              status: 'fail',
                              value: t('denied'),
                              fix: t(
                                  'The server cannot read the repository. Add the server SSH key to GitHub as a deploy key, then check again.',
                              ),
                              link: {
                                  label: t('SSH key'),
                                  href: '#ssh-key',
                                  anchor: true,
                              },
                          };

                case 'ssh_key':
                    return yes
                        ? {
                              key,
                              label: t('Server SSH key'),
                              status: 'ok',
                              value: t('present'),
                          }
                        : {
                              key,
                              label: t('Server SSH key'),
                              status: 'warn',
                              value: t('missing'),
                              fix: t(
                                  'Create one on the server page, then add it to GitHub as a deploy key (not needed for public repositories).',
                              ),
                              link: {
                                  label: t('Server'),
                                  href: servers.show(p.server_id).url,
                              },
                          };

                default:
                    return {
                        key,
                        label: key,
                        status: raw === 'no' ? 'warn' : 'ok',
                        value: raw,
                    };
            }
        },
    },
});
</script>
