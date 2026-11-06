<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <AppHeader @open-menu="menuOpen = true" />
        <MobileMenu :open="menuOpen" @close="menuOpen = false" />

        <!-- Deployments wait in the queue: nobody runs "php artisan queue:work". -->
        <div
            v-if="$page.props.queueStuck"
            class="border-b border-amber-200 bg-amber-50 print:hidden"
        >
            <div
                class="mx-auto flex max-w-7xl items-start gap-2.5 px-4 py-2.5 text-sm text-amber-800 sm:px-6 lg:px-8"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                <p>
                    {{
                        $t(
                            'A deployment is waiting in the queue. Start the queue worker:',
                        )
                    }}
                    <code
                        class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs"
                        >php artisan queue:work --timeout=3600</code
                    >
                    {{ $t('(or use start-autodeploy.bat).') }}
                </p>
            </div>
        </div>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <slot />
        </main>

        <FlashToasts />
        <CommandOutputModal />
    </div>
</template>

<script lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { defineComponent } from 'vue';
import CommandOutputModal from '@/components/general/CommandOutputModal.vue';
import FlashToasts from '@/components/general/FlashToasts.vue';
import AppHeader from '@/components/layout/AppHeader.vue';
import MobileMenu from '@/components/layout/MobileMenu.vue';
import { setLocale } from '@/i18n';

export default defineComponent({
    name: 'AppLayout',

    components: {
        AppHeader,
        CommandOutputModal,
        FlashToasts,
        MobileMenu,
        TriangleAlert,
    },

    data() {
        return {
            menuOpen: false,
        };
    },

    watch: {
        // Close the mobile menu after navigating to another page.
        '$page.url'() {
            this.menuOpen = false;
        },

        // Keep number / date formatting in the language chosen on the server.
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
