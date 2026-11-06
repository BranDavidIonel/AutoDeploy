<template>
    <AppCard
        :title="$t('Installed software')"
        :description="
            checked
                ? $t('Read at the last connection test.')
                : $t('Run Test connection to see what is installed.')
        "
    >
        <div
            class="grid grid-cols-1 gap-2.5 min-[420px]:grid-cols-2 xl:grid-cols-4"
        >
            <div
                v-for="tool in tools"
                :key="tool.key"
                class="flex items-center gap-3 rounded-lg border px-3 py-2.5"
                :class="
                    tool.version
                        ? 'border-slate-200'
                        : tool.required && checked
                          ? 'border-amber-200 bg-amber-50/40'
                          : 'border-dashed border-slate-200'
                "
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-md"
                    :class="iconClass(tool)"
                >
                    <Check v-if="tool.version" class="size-4" />
                    <TriangleAlert
                        v-else-if="tool.required && checked"
                        class="size-4"
                    />
                    <Minus v-else class="size-4" />
                </span>
                <div class="min-w-0">
                    <p
                        class="flex items-baseline gap-1.5 text-sm font-medium text-slate-900"
                    >
                        {{ tool.label }}
                    </p>
                    <p
                        class="truncate text-xs"
                        :class="
                            tool.version
                                ? 'font-mono text-slate-600'
                                : 'text-slate-400'
                        "
                        :title="tool.hint"
                    >
                        {{
                            tool.version ||
                            (checked ? $t('not installed') : '—')
                        }}
                    </p>
                </div>
            </div>
        </div>
        <p
            v-if="checked && missingRequired.length"
            class="mt-4 text-xs text-amber-700"
        >
            {{
                $t(
                    ':tools must be installed on the server before the first deploy.',
                    {
                        tools: missingRequired.join(', '),
                    },
                )
            }}
        </p>
    </AppCard>
</template>

<script lang="ts">
import { Check, Minus, TriangleAlert } from '@lucide/vue';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import AppCard from '@/components/general/AppCard.vue';
import type { ServerInfo } from '@/types';

type Tool = {
    key: keyof ServerInfo;
    label: string;
    hint: string;
    required: boolean;
    version: string;
};

/** Versions of PHP, Composer, Node, Git, Nginx… found on the server ("not installed" when missing). */
export default defineComponent({
    name: 'ServerSoftware',

    components: { AppCard, Check, Minus, TriangleAlert },

    props: {
        info: { type: Object as PropType<ServerInfo>, required: true },
        /** The server was inspected at least once. */
        checked: { type: Boolean, default: false },
    },

    computed: {
        tools(): Tool[] {
            const list: Omit<Tool, 'version'>[] = [
                {
                    key: 'php',
                    label: 'PHP',
                    hint: this.$t('Runs the Laravel app'),
                    required: true,
                },
                {
                    key: 'composer',
                    label: 'Composer',
                    hint: this.$t('Installs the PHP packages'),
                    required: true,
                },
                {
                    key: 'git',
                    label: 'Git',
                    hint: this.$t('Clones the repository'),
                    required: true,
                },
                {
                    key: 'nginx',
                    label: 'Nginx',
                    hint: this.$t('Web server'),
                    required: false,
                },
                {
                    key: 'node',
                    label: 'Node.js',
                    hint: this.$t('Only to build the front-end on the server'),
                    required: false,
                },
                {
                    key: 'npm',
                    label: 'npm',
                    hint: this.$t('Only to build the front-end on the server'),
                    required: false,
                },
                {
                    key: 'mysql',
                    label: 'MySQL',
                    hint: this.$t('Database'),
                    required: false,
                },
                {
                    key: 'supervisor',
                    label: 'Supervisor',
                    hint: this.$t('Keeps the queue workers running'),
                    required: false,
                },
            ];

            return list.map((tool) => ({
                ...tool,
                version: (this.info[tool.key] ?? '').trim(),
            }));
        },

        missingRequired(): string[] {
            return this.tools
                .filter((tool) => tool.required && !tool.version)
                .map((tool) => tool.label);
        },
    },

    methods: {
        iconClass(tool: Tool): string {
            if (tool.version) {
                return 'bg-emerald-50 text-emerald-600';
            }

            return tool.required && this.checked
                ? 'bg-amber-100 text-amber-600'
                : 'bg-slate-100 text-slate-400';
        },
    },
});
</script>
