<template>
    <AppCard :title="$t('Host key')">
        <div v-if="server.host_fingerprint">
            <div
                class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-100 ring-inset"
            >
                <Fingerprint class="size-4 shrink-0 text-emerald-600" />
                <code
                    class="min-w-0 flex-1 truncate font-mono text-xs text-slate-700"
                    :title="server.host_fingerprint"
                    >{{ server.host_fingerprint }}</code
                >
                <CopyButton :text="server.host_fingerprint" class="shrink-0" />
            </div>
            <p class="mt-3 text-sm leading-relaxed text-slate-500">
                {{
                    $t(
                        'Saved at the first connection. If the server answers with a different key, AutoDeploy refuses to connect: that protects you from someone pretending to be your server.',
                    )
                }}
            </p>
            <p class="mt-2 text-sm leading-relaxed text-slate-500">
                {{
                    $t(
                        'Rebuilt the server or moved the IP to a new machine? Forget the key; the next connection saves the new one.',
                    )
                }}
            </p>
            <AppButton
                class="mt-4"
                variant="secondary"
                size="sm"
                :icon="RotateCcw"
                @click="confirming = true"
                >{{ $t('Forget host key') }}</AppButton
            >
        </div>
        <p v-else class="flex items-start gap-2.5 text-sm text-slate-500">
            <Fingerprint class="mt-0.5 size-4 shrink-0 text-slate-400" />
            {{
                $t(
                    'No host key saved yet. It is saved at the first successful connection and checked on every connection after that.',
                )
            }}
        </p>

        <ConfirmModal
            :show="confirming"
            :title="$t('Forget the host key?')"
            :message="
                $t(
                    'Do this only if you know the server changed. The next connection trusts and saves whatever key the server sends.',
                )
            "
            :confirm-label="$t('Forget host key')"
            variant="primary"
            :processing="processing"
            @close="confirming = false"
            @confirm="forget"
        />
    </AppCard>
</template>

<script lang="ts">
import { Fingerprint, RotateCcw } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import CopyButton from '@/components/general/CopyButton.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import serverRoutes from '@/routes/servers';
import type { Server } from '@/types';

/** The SSH host key fingerprint AutoDeploy trusts for this server, with "Forget host key". */
export default defineComponent({
    name: 'HostKeyCard',

    components: { AppButton, AppCard, ConfirmModal, CopyButton, Fingerprint },

    props: {
        server: { type: Object as PropType<Server>, required: true },
    },

    data() {
        return {
            confirming: false,
            processing: false,
            RotateCcw: markRaw(RotateCcw),
        };
    },

    methods: {
        forget(): void {
            router.post(
                serverRoutes.forgetHostKey(this.server.id).url,
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
