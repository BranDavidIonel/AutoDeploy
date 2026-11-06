<template>
    <AppCard
        :title="$t('Deploy on git push (webhook)')"
        :description="
            $t(
                'GitHub calls this URL after a push; a push to :branch starts a deploy.',
                {
                    branch: project.branch,
                },
            )
        "
    >
        <template #actions>
            <StatusBadge
                :status="project.auto_deploy ? 'enabled' : 'disabled'"
                :label="project.auto_deploy ? $t('On') : $t('Off')"
            />
        </template>

        <!-- The URL -->
        <div
            class="flex items-center gap-2 rounded-lg bg-slate-50 py-1.5 pr-1.5 pl-3 ring-1 ring-slate-200 ring-inset"
        >
            <Webhook class="size-4 shrink-0 text-slate-400" />
            <code
                class="min-w-0 flex-1 truncate font-mono text-xs text-slate-700"
                :title="webhookUrl"
                >{{ webhookUrl }}</code
            >
            <CopyButton
                :text="webhookUrl"
                :label="$t('Copy')"
                class="shrink-0"
            />
        </div>

        <p
            v-if="!project.auto_deploy"
            class="mt-3 flex items-start gap-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"
        >
            <Info class="mt-px size-3.5 shrink-0 text-slate-400" />
            {{
                $t(
                    'Deploy on git push is off: GitHub’s calls are refused until you turn it on in the form above.',
                )
            }}
        </p>

        <!-- How to add it in GitHub -->
        <ol class="mt-5 space-y-2.5 text-sm text-slate-600">
            <li v-for="(step, index) in steps" :key="index" class="flex gap-3">
                <span
                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 ring-1 ring-brand-100"
                    >{{ index + 1 }}</span
                >
                <span>
                    {{ step.text }}
                    <code v-if="step.code" class="code-inline">{{
                        step.code
                    }}</code>
                </span>
            </li>
        </ol>

        <p
            class="mt-5 flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-800"
        >
            <TriangleAlert class="mt-px size-3.5 shrink-0 text-amber-500" />
            {{
                $t(
                    'This only works when AutoDeploy is reachable from the internet. On localhost or a home PC, GitHub cannot call it: deploy with the button instead.',
                )
            }}
        </p>

        <div
            class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-xs text-slate-500">
                {{ $t('The URL is the secret. If it leaked, make a new one.') }}
            </p>
            <AppButton
                variant="secondary"
                size="sm"
                :icon="RefreshCw"
                class="shrink-0"
                @click="confirming = true"
                >{{ $t('New URL') }}</AppButton
            >
        </div>

        <ConfirmModal
            :show="confirming"
            :title="$t('Create a new webhook URL?')"
            :message="
                $t(
                    'The current URL stops working right away. Paste the new one in GitHub → Settings → Webhooks.',
                )
            "
            :confirm-label="$t('New URL')"
            variant="primary"
            :processing="processing"
            @close="confirming = false"
            @confirm="regenerate"
        />
    </AppCard>
</template>

<script lang="ts">
import { Info, RefreshCw, TriangleAlert, Webhook } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import CopyButton from '@/components/general/CopyButton.vue';
import StatusBadge from '@/components/general/StatusBadge.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import projectRoutes from '@/routes/projects';
import type { Project } from '@/types';

/** The project's GitHub webhook URL, how to add it, and "New URL". */
export default defineComponent({
    name: 'WebhookCard',

    components: {
        AppButton,
        AppCard,
        ConfirmModal,
        CopyButton,
        Info,
        StatusBadge,
        TriangleAlert,
        Webhook,
    },

    props: {
        project: { type: Object as PropType<Project>, required: true },
        webhookUrl: { type: String, required: true },
    },

    data() {
        return {
            confirming: false,
            processing: false,
            RefreshCw: markRaw(RefreshCw),
        };
    },

    computed: {
        steps(): { text: string; code?: string }[] {
            return [
                {
                    text: this.$t(
                        'In GitHub open the repository → Settings → Webhooks → Add webhook.',
                    ),
                },
                { text: this.$t('Payload URL: the URL above.') },
                { text: this.$t('Content type:'), code: 'application/json' },
                { text: this.$t('Which events: “Just the push event”. Save.') },
            ];
        },
    },

    methods: {
        regenerate(): void {
            router.post(
                projectRoutes.webhook.regenerate(this.project.id).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => (this.processing = true),
                    onFinish: () => (this.processing = false),
                    onSuccess: () => (this.confirming = false),
                },
            );
        },
    },
});
</script>
