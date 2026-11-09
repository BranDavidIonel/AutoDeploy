<template>
    <div
        class="flex items-start gap-3 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-100 ring-inset"
    >
        <Zap class="mt-0.5 size-4 shrink-0 text-sky-600" />
        <p>
            {{
                $t(
                    'First deploy: run Quick setup first (it creates the folders and imports .env and storage).',
                )
            }}
            <Link
                :href="setupUrl"
                class="font-medium whitespace-nowrap text-sky-700 underline underline-offset-2 hover:text-sky-900"
                >{{ $t('Open Quick setup') }}</Link
            >
        </p>
    </div>
</template>

<script lang="ts">
import { Zap } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import projects from '@/routes/projects';

/** Shown until the first deploy worked: Quick setup prepares the server before it. */
export default defineComponent({
    name: 'FirstDeployHint',

    components: { Link, Zap },

    props: {
        projectId: { type: Number, required: true },
    },

    computed: {
        setupUrl(): string {
            return projects.setup.show(this.projectId).url;
        },
    },
});
</script>
