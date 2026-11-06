<template>
    <Head :title="$t('My account')" />

    <PageHeader
        :title="$t('My account')"
        :description="$t('Your name, email and password for AutoDeploy.')"
    />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Profile -->
        <AppCard
            :title="$t('Profile')"
            :description="$t('Shown on the deployments you start.')"
        >
            <form class="space-y-5" @submit.prevent="saveProfile">
                <div class="flex items-center gap-4">
                    <span
                        class="flex size-14 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-slate-700 to-slate-900 text-lg font-semibold text-white shadow-sm"
                    >
                        {{ avatar }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-900">
                            {{ profile.name || '—' }}
                        </p>
                        <p class="truncate text-sm text-slate-500">
                            {{ profile.email }}
                        </p>
                    </div>
                </div>

                <TextInput
                    v-model="profile.name"
                    :label="$t('Name')"
                    :error="profile.errors.name"
                    autocomplete="name"
                    required
                />
                <TextInput
                    v-model="profile.email"
                    type="email"
                    :label="$t('Email')"
                    :hint="$t('You log in with this address.')"
                    :error="profile.errors.email"
                    autocomplete="email"
                    required
                />

                <div
                    class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4"
                >
                    <Transition
                        enter-active-class="transition duration-200"
                        enter-from-class="opacity-0"
                        leave-active-class="transition duration-300"
                        leave-to-class="opacity-0"
                    >
                        <span
                            v-if="profile.recentlySuccessful"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600"
                        >
                            <Check class="size-4" />
                            {{ $t('Saved') }}
                        </span>
                    </Transition>
                    <AppButton
                        type="submit"
                        :icon="Save"
                        :loading="profile.processing"
                        :disabled="!profile.isDirty"
                        >{{ $t('Save profile') }}</AppButton
                    >
                </div>
            </form>
        </AppCard>

        <!-- Password -->
        <AppCard
            :title="$t('Change password')"
            :description="
                $t('Use at least 8 characters. A password manager helps.')
            "
        >
            <form class="space-y-5" @submit.prevent="savePassword">
                <TextInput
                    v-model="password.current_password"
                    type="password"
                    :label="$t('Current password')"
                    :error="password.errors.current_password"
                    autocomplete="current-password"
                    required
                />
                <TextInput
                    v-model="password.password"
                    type="password"
                    :label="$t('New password')"
                    :error="password.errors.password"
                    autocomplete="new-password"
                    required
                />
                <TextInput
                    v-model="password.password_confirmation"
                    type="password"
                    :label="$t('Repeat the new password')"
                    :error="password.errors.password_confirmation || mismatch"
                    autocomplete="new-password"
                    required
                />

                <div
                    class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4"
                >
                    <Transition
                        enter-active-class="transition duration-200"
                        enter-from-class="opacity-0"
                        leave-active-class="transition duration-300"
                        leave-to-class="opacity-0"
                    >
                        <span
                            v-if="password.recentlySuccessful"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600"
                        >
                            <Check class="size-4" />
                            {{ $t('Changed') }}
                        </span>
                    </Transition>
                    <AppButton
                        type="submit"
                        :icon="KeyRound"
                        :loading="password.processing"
                        >{{ $t('Change password') }}</AppButton
                    >
                </div>
            </form>
        </AppCard>
    </div>
</template>

<script lang="ts">
import { Check, KeyRound, Save } from '@lucide/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import AppCard from '@/components/general/AppCard.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import PageHeader from '@/components/layout/PageHeader.vue';
import account from '@/routes/account';
import { initials } from '@/utils/format';

/** The logged-in user's profile and password. */
export default defineComponent({
    name: 'AccountEdit',

    components: { AppButton, AppCard, Check, Head, PageHeader, TextInput },

    data() {
        const user = this.$page.props.auth.user;

        return {
            KeyRound: markRaw(KeyRound),
            Save: markRaw(Save),
            profile: useForm({
                name: user?.name ?? '',
                email: user?.email ?? '',
            }),
            password: useForm({
                current_password: '',
                password: '',
                password_confirmation: '',
            }),
        };
    },

    computed: {
        avatar(): string {
            return initials(this.profile.name || 'A');
        },

        /** Said right away, before the server does. */
        mismatch(): string {
            const { password, password_confirmation } = this.password;

            return password_confirmation && password !== password_confirmation
                ? this.$t('The two passwords are not the same.')
                : '';
        },
    },

    methods: {
        saveProfile(): void {
            this.profile.submit(account.profile(), {
                preserveScroll: true,
                // The new values become the "clean" state of the form.
                onSuccess: () => this.profile.defaults(),
            });
        },

        savePassword(): void {
            this.password.submit(account.password(), {
                preserveScroll: true,
                onSuccess: () => this.password.reset(),
                onError: (errors) => {
                    if (errors.password) {
                        this.password.reset(
                            'password',
                            'password_confirmation',
                        );
                    }

                    if (errors.current_password) {
                        this.password.reset('current_password');
                    }
                },
            });
        },
    },
});
</script>
