<template>
    <AppModal
        :show="output !== null"
        :title="output?.title ?? ''"
        size="lg"
        @close="output = null"
    >
        <div
            v-if="output"
            class="mb-3 flex items-center gap-2 text-sm font-medium"
            :class="output.ok ? 'text-emerald-700' : 'text-rose-700'"
        >
            <CircleCheck v-if="output.ok" class="size-4" />
            <CircleX v-else class="size-4" />
            {{
                output.ok
                    ? $t('Finished without errors')
                    : $t('Finished with errors')
            }}
        </div>
        <LogOutput
            v-if="output"
            :text="output.text"
            :title="$t('Server output')"
            max-height="max-h-[55vh]"
        />
        <template #footer>
            <AppButton variant="secondary" @click="output = null">{{
                $t('Close')
            }}</AppButton>
        </template>
    </AppModal>
</template>

<script lang="ts">
import { CircleCheck, CircleX } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import LogOutput from '@/components/general/LogOutput.vue';
import AppModal from '@/components/modals/AppModal.vue';
import type { CommandOutput } from '@/types';

/** Shows the output of a server command (quick setup, workers, scheduler) sent as flash "output". */
export default defineComponent({
    name: 'CommandOutputModal',

    components: { AppButton, AppModal, CircleCheck, CircleX, LogOutput },

    data() {
        return {
            output: null as CommandOutput | null,
            stopListening: null as null | (() => void),
        };
    },

    mounted() {
        this.show(this.$page.flash as { output?: CommandOutput } | undefined);
        this.stopListening = router.on('flash', (event) =>
            this.show(event.detail.flash as { output?: CommandOutput }),
        );
    },

    beforeUnmount() {
        this.stopListening?.();
    },

    methods: {
        show(flash: { output?: CommandOutput } | undefined): void {
            if (flash?.output) {
                this.output = flash.output;
            }
        },
    },
});
</script>
