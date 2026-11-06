<template>
    <header
        class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur print:hidden"
    >
        <div
            class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4 sm:gap-4 sm:px-6 lg:px-8"
        >
            <!-- Phones: open the side menu -->
            <button
                type="button"
                class="-ml-1 shrink-0 rounded-md p-2 text-slate-500 hover:bg-slate-100 md:hidden"
                :aria-label="$t('Menu')"
                @click="$emit('open-menu')"
            >
                <Menu class="size-5" />
            </button>

            <Link
                :href="homeUrl"
                class="flex min-w-0 items-center gap-2.5 md:shrink-0"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-brand-500 to-violet-600 shadow-sm shadow-brand-600/30"
                >
                    <Rocket class="size-[18px] text-white" />
                </span>
                <span
                    class="truncate text-[15px] font-semibold tracking-tight text-slate-900"
                >
                    AutoDeploy
                </span>
            </Link>

            <nav class="ml-4 hidden h-full items-stretch gap-1 md:flex">
                <Link
                    v-for="item in items"
                    :key="item.label"
                    :href="item.href"
                    class="relative flex items-center gap-1.5 px-3 text-sm font-medium transition"
                    :class="
                        active(item)
                            ? 'text-brand-700'
                            : 'text-slate-500 hover:text-slate-900'
                    "
                >
                    <component :is="item.icon" class="size-4" />
                    {{ $t(item.label) }}
                    <span
                        v-if="active(item)"
                        class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-600"
                    />
                </Link>
            </nav>

            <div class="ml-auto flex shrink-0 items-center gap-2 sm:gap-3">
                <LanguageSwitcher />

                <div ref="menu" class="relative">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-full p-0.5 pr-2 transition hover:bg-slate-100"
                        :aria-expanded="userMenu"
                        @click="userMenu = !userMenu"
                    >
                        <span
                            class="flex size-8 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white"
                        >
                            {{ userInitials }}
                        </span>
                        <ChevronDown
                            class="hidden size-4 text-slate-400 sm:block"
                        />
                    </button>
                    <div
                        v-if="userMenu"
                        class="absolute top-full right-0 z-40 mt-2 w-60 rounded-lg border border-slate-200 bg-white p-1 shadow-lg"
                    >
                        <div class="border-b border-slate-100 px-3 py-2.5">
                            <p
                                class="truncate text-sm font-medium text-slate-900"
                            >
                                {{ user?.name }}
                            </p>
                            <p class="truncate text-xs text-slate-500">
                                {{ user?.email }}
                            </p>
                        </div>
                        <Link
                            :href="accountUrl"
                            class="mt-1 flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                        >
                            <UserRound class="size-4 text-slate-400" />
                            {{ $t('My account') }}
                        </Link>
                        <Link
                            :href="logoutUrl"
                            method="post"
                            as="button"
                            class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                        >
                            <LogOut class="size-4 text-slate-400" />
                            {{ $t('Log out') }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>

<script lang="ts">
import { ChevronDown, LogOut, Menu, Rocket, UserRound } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import LanguageSwitcher from '@/components/layout/LanguageSwitcher.vue';
import { dashboard, logout } from '@/routes';
import account from '@/routes/account';
import { initials } from '@/utils/format';
import { isActive, navigationItems } from '@/utils/navigation';
import type { NavItem } from '@/utils/navigation';

/** Top bar: brand, main menu (desktop), language, user menu. */
export default defineComponent({
    name: 'AppHeader',

    components: {
        ChevronDown,
        LanguageSwitcher,
        Link,
        LogOut,
        Menu,
        Rocket,
        UserRound,
    },

    emits: ['open-menu'],

    data() {
        return {
            userMenu: false,
        };
    },

    computed: {
        items(): NavItem[] {
            return navigationItems();
        },

        homeUrl(): string {
            return dashboard().url;
        },

        accountUrl(): string {
            return account.edit().url;
        },

        logoutUrl(): string {
            return logout().url;
        },

        user(): { name: string; email: string } | null {
            return this.$page.props.auth.user;
        },

        userInitials(): string {
            return initials(this.user?.name ?? 'A');
        },
    },

    watch: {
        '$page.url'() {
            this.userMenu = false;
        },
    },

    mounted() {
        document.addEventListener('mousedown', this.onClickOutside);
    },

    beforeUnmount() {
        document.removeEventListener('mousedown', this.onClickOutside);
    },

    methods: {
        active(item: NavItem): boolean {
            return isActive(item, this.$page.url);
        },

        onClickOutside(event: MouseEvent): void {
            const menu = this.$refs.menu as HTMLElement | undefined;

            if (this.userMenu && !menu?.contains(event.target as Node)) {
                this.userMenu = false;
            }
        },
    },
});
</script>
