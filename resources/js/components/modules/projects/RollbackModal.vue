<template>
    <AppModal
        :show="show"
        :title="
            $t('Roll back to release :release?', {
                release: target?.release ?? '—',
            })
        "
        size="md"
        @close="$emit('close')"
    >
        <div v-if="target" class="space-y-4 text-sm leading-relaxed">
            <p class="text-slate-600">
                {{
                    $t(
                        'The site switches to release :release (commit :commit) right away and the "After going live" commands run again.',
                        {
                            release: target.release ?? '—',
                            commit: target.short_commit ?? '—',
                        },
                    )
                }}
            </p>

            <!-- The database backup made while that release was live -->
            <div
                v-if="backup"
                class="rounded-lg border p-4 transition"
                :class="
                    restoreDatabase
                        ? 'border-amber-300 bg-amber-50/70'
                        : 'border-slate-200'
                "
            >
                <ToggleInput
                    v-model="restoreDatabase"
                    :label="optionLabel"
                    :description="
                        $t(
                            'Backup #:id of :date (:size), made while this release was live.',
                            {
                                id: backup.id,
                                date: formatDateTime(backup.created_at),
                                size: formatBytes(backup.size),
                            },
                        )
                    "
                />
                <ul
                    v-if="restoreDatabase"
                    class="mt-3 space-y-1.5 pl-14 text-xs text-amber-900"
                >
                    <li class="flex items-start gap-1.5">
                        <TriangleAlert
                            class="mt-px size-3.5 shrink-0 text-amber-600"
                        />
                        {{
                            $t(
                                'The tables go back to that moment: data saved since then is lost.',
                            )
                        }}
                    </li>
                    <li class="flex items-start gap-1.5">
                        <ShieldCheck
                            class="mt-px size-3.5 shrink-0 text-amber-600"
                        />
                        {{
                            $t(
                                'A safety backup of the current database is made first (Backups tab), so this can be undone.',
                            )
                        }}
                    </li>
                    <li class="flex items-start gap-1.5">
                        <Construction
                            class="mt-px size-3.5 shrink-0 text-amber-600"
                        />
                        {{
                            $t(
                                'The site is in maintenance mode (503) while the database is restored.',
                            )
                        }}
                    </li>
                </ul>
            </div>

            <p
                v-else
                class="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-600 ring-1 ring-slate-200 ring-inset"
            >
                <Info class="mt-px size-3.5 shrink-0 text-slate-400" />
                {{
                    $t(
                        'Only the code goes back: the database stays as it is (migrations are not reverted). No database backup of this release is on the server.',
                    )
                }}
            </p>
        </div>

        <template #footer>
            <AppButton variant="secondary" @click="$emit('close')">{{
                $t('Cancel')
            }}</AppButton>
            <AppButton
                :variant="restoreDatabase && backup ? 'danger' : 'primary'"
                :icon="RotateCcw"
                :loading="processing"
                @click="$emit('confirm', restoreDatabase && !!backup)"
                >{{
                    restoreDatabase && backup
                        ? $t('Roll back code and database')
                        : $t('Roll back')
                }}</AppButton
            >
        </template>
    </AppModal>
</template>

<script lang="ts">
import {
    Construction,
    Info,
    RotateCcw,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import type { PropType } from 'vue';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import ToggleInput from '@/components/inputs/ToggleInput.vue';
import AppModal from '@/components/modals/AppModal.vue';
import type { Backup, Deployment } from '@/types';
import { formatBytes, formatDateTime } from '@/utils/format';

/**
 * Confirms a rollback. When the older release still has a database backup on the server,
 * the owner can put the database back too (off by default: it loses newer data).
 */
export default defineComponent({
    name: 'RollbackModal',

    components: {
        AppButton,
        AppModal,
        Construction,
        Info,
        ShieldCheck,
        ToggleInput,
        TriangleAlert,
    },

    props: {
        show: { type: Boolean, default: false },
        /** The finished deploy whose release goes live again. */
        target: {
            type: Object as PropType<Deployment | null>,
            default: null,
        },
        /** The database backup a rollback to it can put back (null: none on the server). */
        backup: { type: Object as PropType<Backup | null>, default: null },
        processing: { type: Boolean, default: false },
    },

    emits: ['confirm', 'close'],

    data() {
        return {
            restoreDatabase: false,
            RotateCcw: markRaw(RotateCcw),
        };
    },

    computed: {
        optionLabel(): string {
            const backup = this.backup;

            if (backup?.kind === 'before_deploy' && backup.deployment_id) {
                return this.$t(
                    'Also restore the database as it was before deploy #:id',
                    { id: backup.deployment_id },
                );
            }

            return this.$t('Also restore the database from that backup');
        },
    },

    watch: {
        show(open: boolean) {
            // Every time it opens, the database option starts off.
            if (open) {
                this.restoreDatabase = false;
            }
        },
    },

    methods: {
        formatBytes,
        formatDateTime,
    },
});
</script>
