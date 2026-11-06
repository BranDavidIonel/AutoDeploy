<template>
    <div
        class="flex items-center gap-0.5 rounded-md border border-slate-200 bg-white p-0.5 shadow-xs"
        role="group"
        :aria-label="$t('Language')"
    >
        <Languages class="mx-1.5 hidden size-4 text-slate-400 sm:block" />
        <button
            v-for="(label, code) in $page.props.locales"
            :key="code"
            type="button"
            :title="label"
            class="rounded-lg px-2.5 py-1.5 text-xs font-semibold tracking-wide uppercase transition"
            :class="
                code === $page.props.locale
                    ? 'bg-brand-600 text-white shadow-sm'
                    : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'
            "
            :disabled="switching"
            @click="switchTo(String(code))"
        >
            {{ code }}
        </button>
    </div>
</template>

<script lang="ts">
import { Languages } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import localeRoutes from '@/routes/locale';

/** RO / EN switch. The server remembers the choice in a cookie and re-renders the page. */
export default defineComponent({
    name: 'LanguageSwitcher',

    components: { Languages },

    data() {
        return {
            switching: false,
        };
    },

    methods: {
        switchTo(locale: string): void {
            if (locale === this.$page.props.locale) {
                return;
            }

            router.put(
                localeRoutes.update().url,
                { locale },
                {
                    preserveScroll: true,
                    onStart: () => (this.switching = true),
                    onFinish: () => (this.switching = false),
                },
            );
        },
    },
});
</script>
