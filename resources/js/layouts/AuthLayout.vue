<template>
    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-950 px-4 py-12"
    >
        <!-- Soft glow behind the card -->
        <div
            class="pointer-events-none absolute -top-40 left-1/2 size-[42rem] -translate-x-1/2 rounded-full bg-brand-600/25 blur-3xl"
        />
        <div
            class="pointer-events-none absolute -bottom-48 -left-24 size-[30rem] rounded-full bg-violet-600/20 blur-3xl"
        />
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] bg-[size:24px_24px]"
        />

        <div class="relative w-full max-w-sm">
            <slot />
        </div>

        <div class="absolute top-4 right-4">
            <LanguageSwitcher />
        </div>

        <FlashToasts />
    </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import FlashToasts from '@/components/general/FlashToasts.vue';
import LanguageSwitcher from '@/components/layout/LanguageSwitcher.vue';
import { setLocale } from '@/i18n';

export default defineComponent({
    name: 'AuthLayout',

    components: { FlashToasts, LanguageSwitcher },

    watch: {
        '$page.props.locale': {
            immediate: true,
            handler(locale: string) {
                setLocale(locale);
                document.documentElement.lang = locale;
            },
        },
    },
});
</script>
