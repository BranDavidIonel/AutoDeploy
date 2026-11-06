<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset"
        :class="style.badge"
    >
        <span
            class="size-1.5 rounded-full"
            :class="[style.dot, { 'animate-pulse': pulse }]"
        />
        {{ text }}
    </span>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

const GREEN = {
    badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    dot: 'bg-emerald-500',
};
const RED = {
    badge: 'bg-rose-50 text-rose-700 ring-rose-200',
    dot: 'bg-rose-500',
};
const AMBER = {
    badge: 'bg-amber-50 text-amber-700 ring-amber-200',
    dot: 'bg-amber-500',
};
const BLUE = {
    badge: 'bg-sky-50 text-sky-700 ring-sky-200',
    dot: 'bg-sky-500',
};
const BRAND = {
    badge: 'bg-brand-50 text-brand-700 ring-brand-200',
    dot: 'bg-brand-500',
};
const GRAY = {
    badge: 'bg-slate-50 text-slate-600 ring-slate-200',
    dot: 'bg-slate-400',
};

const STYLES: Record<string, { badge: string; dot: string; label: string }> = {
    // Deployments and their steps
    pending: { ...GRAY, label: 'Queued' },
    running: { ...BRAND, label: 'Running' },
    finished: { ...GREEN, label: 'Finished' },
    failed: { ...RED, label: 'Failed' },
    cancelled: { ...AMBER, label: 'Cancelled' },
    skipped: { ...GRAY, label: 'Skipped' },
    live: { ...GREEN, label: 'Live' },
    // Servers
    connected: { ...GREEN, label: 'Connected' },
    unknown: { ...GRAY, label: 'Not checked' },
    // Workers (Supervisor)
    not_installed: { ...GRAY, label: 'Not installed' },
    starting: { ...BLUE, label: 'Starting' },
    stopped: { ...AMBER, label: 'Stopped' },
    fatal: { ...RED, label: 'Crashed' },
    // Generic
    enabled: { ...GREEN, label: 'On' },
    disabled: { ...GRAY, label: 'Off' },
};

/** Colored pill for any status: deployment, step, server, worker. */
export default defineComponent({
    name: 'StatusBadge',

    props: {
        status: { type: String, required: true },
        /** Overrides the default text for the status. */
        label: { type: String, default: '' },
    },

    computed: {
        style(): { badge: string; dot: string; label: string } {
            return STYLES[this.status] ?? { ...GRAY, label: this.status };
        },

        text(): string {
            return this.label || this.$t(this.style.label);
        },

        pulse(): boolean {
            return this.status === 'running' || this.status === 'starting';
        },
    },
});
</script>
