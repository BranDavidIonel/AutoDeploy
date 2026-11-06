<template>
    <AppModal
        :show="show"
        :title="title || $t('Are you sure?')"
        size="sm"
        @close="$emit('close')"
    >
        <div class="flex gap-4">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-full"
                :class="
                    variant === 'danger'
                        ? 'bg-rose-50 text-rose-600'
                        : 'bg-brand-50 text-brand-600'
                "
            >
                <AlertTriangle class="size-5" />
            </div>
            <p class="text-sm leading-relaxed text-slate-600">{{ message }}</p>
        </div>

        <template #footer>
            <AppButton variant="secondary" @click="$emit('close')">{{
                $t('Cancel')
            }}</AppButton>
            <AppButton
                :variant="variant"
                :loading="processing"
                @click="$emit('confirm')"
                >{{ confirmLabel || $t('Confirm') }}</AppButton
            >
        </template>
    </AppModal>
</template>

<script lang="ts">
import { AlertTriangle } from '@lucide/vue';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppModal from '@/components/modals/AppModal.vue';

/** "Are you sure?" dialog for destructive or important actions. */
export default defineComponent({
    name: 'ConfirmModal',

    components: { AlertTriangle, AppButton, AppModal },

    props: {
        show: { type: Boolean, default: false },
        title: { type: String, default: '' },
        message: { type: String, required: true },
        confirmLabel: { type: String, default: '' },
        variant: {
            type: String as PropType<'danger' | 'primary' | 'success'>,
            default: 'danger',
        },
        processing: { type: Boolean, default: false },
    },

    emits: ['confirm', 'close'],
});
</script>
