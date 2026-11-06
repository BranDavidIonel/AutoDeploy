import { createInertiaApp } from '@inertiajs/vue3';
import { i18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'AutoDeploy';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // The login page gets the centered layout, every other page the app shell.
    layout: (name: string) =>
        name.startsWith('auth/') ? AuthLayout : AppLayout,
    withApp: (app) => {
        app.use(i18n);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#6366f1',
    },
});
