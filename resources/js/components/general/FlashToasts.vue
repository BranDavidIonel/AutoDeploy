<template>
    <div
        class="pointer-events-none fixed inset-x-0 bottom-4 z-[70] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6"
    >
        <TransitionGroup
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-y-2 opacity-0 sm:translate-x-4 sm:translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white p-4 shadow-xl shadow-slate-900/10"
                :class="
                    toast.type === 'success'
                        ? 'border-emerald-200'
                        : 'border-rose-200'
                "
            >
                <CheckCircle2
                    v-if="toast.type === 'success'"
                    class="mt-0.5 size-5 shrink-0 text-emerald-500"
                />
                <AlertTriangle
                    v-else
                    class="mt-0.5 size-5 shrink-0 text-rose-500"
                />
                <p class="flex-1 text-sm text-slate-700">{{ toast.message }}</p>
                <button
                    type="button"
                    class="rounded-md p-0.5 text-slate-400 hover:text-slate-600"
                    @click="remove(toast.id)"
                >
                    <X class="size-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<script lang="ts">
import { AlertTriangle, CheckCircle2, X } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { defineComponent } from 'vue';

type Toast = { id: number; type: 'success' | 'error'; message: string };
type Flash = { success?: string; error?: string } | undefined;

/** Shows the flash messages sent by the controllers (Inertia::flash) as toasts. */
export default defineComponent({
    name: 'FlashToasts',

    components: { AlertTriangle, CheckCircle2, X },

    data() {
        return {
            toasts: [] as Toast[],
            nextId: 1,
            stopListening: null as null | (() => void),
        };
    },

    mounted() {
        this.show(this.$page.flash as Flash);
        this.stopListening = router.on('flash', (event) =>
            this.show(event.detail.flash as Flash),
        );
    },

    beforeUnmount() {
        this.stopListening?.();
    },

    methods: {
        show(flash: Flash): void {
            if (flash?.success) {
                this.push('success', flash.success);
            }

            if (flash?.error) {
                this.push('error', flash.error);
            }
        },

        push(type: Toast['type'], message: string): void {
            // The same message can arrive twice (initial page + flash event).
            if (this.toasts.some((toast) => toast.message === message)) {
                return;
            }

            const id = this.nextId++;
            this.toasts.push({ id, type, message });
            setTimeout(() => this.remove(id), type === 'error' ? 7000 : 4500);
        },

        remove(id: number): void {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    },
});
</script>
