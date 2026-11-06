<template>
    <AppCard
        :title="$t('SSH key for GitHub')"
        :description="
            $t(
                'The server’s own key, so it can clone your private repositories.',
            )
        "
    >
        <template v-if="publicKey">
            <div
                class="overflow-hidden rounded-lg bg-slate-950 ring-1 ring-slate-800"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-white/5 px-3 py-1.5"
                >
                    <span
                        class="truncate font-mono text-[11px] text-slate-400"
                        >{{ keyType }}</span
                    >
                    <CopyButton :text="publicKey" :label="$t('Copy')" dark />
                </div>
                <p
                    class="max-h-32 overflow-y-auto px-3 py-2.5 font-mono text-[11.5px] leading-relaxed break-all text-slate-200 select-all"
                >
                    {{ publicKey }}
                </p>
            </div>
            <ol class="mt-4 space-y-2 text-sm text-slate-600">
                <li class="flex gap-2.5">
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 ring-1 ring-brand-100"
                        >1</span
                    >
                    <span>{{
                        $t(
                            'Add it in GitHub → repository → Settings → Deploy keys.',
                        )
                    }}</span>
                </li>
                <li class="flex gap-2.5">
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 ring-1 ring-brand-100"
                        >2</span
                    >
                    <span>{{
                        $t(
                            'Read access is enough; leave “Allow write access” off.',
                        )
                    }}</span>
                </li>
            </ol>
        </template>

        <div v-else class="text-sm text-slate-600">
            <p>
                {{
                    checked
                        ? $t(
                              'The server has no SSH key yet. Create one, then add its public part to GitHub as a deploy key.',
                          )
                        : $t(
                              'Test the connection first to see if the server already has a key.',
                          )
                }}
            </p>
            <AppButton
                class="mt-4"
                variant="secondary"
                size="sm"
                :icon="KeyRound"
                :loading="generating"
                @click="generate"
                >{{ $t('Create SSH key on the server') }}</AppButton
            >
        </div>
    </AppCard>
</template>

<script lang="ts">
import { KeyRound } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import CopyButton from '@/components/general/CopyButton.vue';
import serverRoutes from '@/routes/servers';
import type { Server } from '@/types';

/** Public SSH key of the server (to add as a GitHub deploy key), or a button that creates one. */
export default defineComponent({
    name: 'DeployKeyCard',

    components: { AppButton, AppCard, CopyButton },

    props: {
        server: { type: Object as PropType<Server>, required: true },
    },

    data() {
        return {
            generating: false,
            KeyRound: markRaw(KeyRound),
        };
    },

    computed: {
        publicKey(): string {
            return (this.server.info.ssh_public_key ?? '').trim();
        },

        /** "ssh-ed25519 · autodeploy@prod" */
        keyType(): string {
            const [type, , comment] = this.publicKey.split(/\s+/);

            return [type, comment].filter(Boolean).join(' · ');
        },

        checked(): boolean {
            return (
                this.server.last_checked_at !== null &&
                this.server.status === 'connected'
            );
        },
    },

    methods: {
        generate(): void {
            router.post(
                serverRoutes.sshKey(this.server.id).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => (this.generating = true),
                    onFinish: () => (this.generating = false),
                },
            );
        },
    },
});
</script>
