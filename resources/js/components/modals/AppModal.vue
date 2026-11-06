<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 backdrop-blur-sm sm:items-center"
                @mousedown.self="close"
            >
                <div
                    class="card w-full overflow-hidden shadow-2xl"
                    :class="widthClass"
                    role="dialog"
                    aria-modal="true"
                >
                    <header
                        class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-4"
                    >
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">
                                {{ title }}
                            </h2>
                            <p
                                v-if="description"
                                class="mt-0.5 text-sm text-slate-500"
                            >
                                {{ description }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            @click="close"
                        >
                            <X class="size-5" />
                        </button>
                    </header>
                    <div class="max-h-[70vh] overflow-y-auto px-6 py-5">
                        <div
                            v-if="error"
                            role="alert"
                            class="mb-4 flex items-start gap-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700"
                        >
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                            {{ error }}
                        </div>
                        <slot />
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-4"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script lang="ts">
import { AlertTriangle, X } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';

const WIDTHS = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl' } as const;

export default defineComponent({
    name: 'AppModal',

    components: { AlertTriangle, X },

    props: {
        show: { type: Boolean, default: false },
        title: { type: String, required: true },
        description: { type: String, default: '' },
        size: { type: String as PropType<keyof typeof WIDTHS>, default: 'md' },
    },

    emits: ['close'],

    data() {
        return {
            // Business error (e.g. demo limit, not enough stock) received while open.
            error: '',
            stopListening: null as null | (() => void),
        };
    },

    computed: {
        widthClass(): string {
            return WIDTHS[this.size];
        },
    },

    watch: {
        show(isOpen: boolean) {
            document.body.style.overflow = isOpen ? 'hidden' : '';
            this.error = '';
        },
    },

    mounted() {
        document.addEventListener('keydown', this.onKeydown);
        this.stopListening = router.on('flash', (event) => {
            const flash = event.detail.flash as { error?: string } | undefined;

            if (this.show && flash?.error) {
                this.error = flash.error;
            }
        });
    },

    beforeUnmount() {
        document.removeEventListener('keydown', this.onKeydown);
        this.stopListening?.();
        document.body.style.overflow = '';
    },

    methods: {
        close(): void {
            this.$emit('close');
        },

        onKeydown(event: KeyboardEvent): void {
            if (this.show && event.key === 'Escape') {
                this.close();
            }
        },
    },
});
</script>
