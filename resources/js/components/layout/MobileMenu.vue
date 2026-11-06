<template>
    <Transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-200"
        leave-to-class="opacity-0"
    >
        <div
            v-if="open"
            class="fixed inset-0 z-40 bg-slate-900/30 backdrop-blur-sm md:hidden"
            @click="$emit('close')"
        />
    </Transition>

    <Transition
        enter-active-class="transition-transform duration-300"
        enter-from-class="-translate-x-full"
        leave-active-class="transition-transform duration-200"
        leave-to-class="-translate-x-full"
    >
        <aside
            v-if="open"
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white shadow-xl md:hidden"
        >
            <div class="flex h-14 items-center justify-between px-5">
                <span class="text-base font-semibold text-slate-900">
                    AutoDeploy
                </span>
                <button
                    type="button"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100"
                    :aria-label="$t('Close')"
                    @click="$emit('close')"
                >
                    <X class="size-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2">
                <Link
                    v-for="item in items"
                    :key="item.label"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition"
                    :class="linkClass(item)"
                >
                    <component :is="item.icon" class="size-5" />
                    {{ $t(item.label) }}
                </Link>
            </nav>

            <div class="border-t border-slate-100 p-3">
                <Link
                    :href="accountUrl"
                    class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
                >
                    <UserRound class="size-5" />
                    {{ $t('My account') }}
                </Link>
            </div>
        </aside>
    </Transition>
</template>

<script lang="ts">
import { UserRound, X } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import account from '@/routes/account';
import { isActive, navigationItems } from '@/utils/navigation';
import type { NavItem } from '@/utils/navigation';

/** Side drawer with the main menu, only on phones. */
export default defineComponent({
    name: 'MobileMenu',

    components: { Link, UserRound, X },

    props: {
        open: { type: Boolean, default: false },
    },

    emits: ['close'],

    computed: {
        items(): NavItem[] {
            return navigationItems();
        },

        accountUrl(): string {
            return account.edit().url;
        },
    },

    methods: {
        linkClass(item: NavItem): string {
            return isActive(item, this.$page.url)
                ? 'bg-brand-50 text-brand-700'
                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900';
        },
    },
});
</script>
