<template>
    <Head :title="$t('Log in')" />

    <div
        class="rounded-2xl border border-white/10 bg-white/[0.04] p-7 shadow-2xl ring-1 shadow-black/40 ring-white/5 backdrop-blur-xl sm:p-8"
    >
        <!-- Brand -->
        <div class="flex flex-col items-center text-center">
            <span
                class="flex size-12 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-violet-600 shadow-lg ring-1 shadow-brand-600/40 ring-white/20"
            >
                <Rocket class="size-6 text-white" />
            </span>
            <h1 class="mt-5 text-xl font-semibold tracking-tight text-white">
                {{ $t('Sign in to AutoDeploy') }}
            </h1>
            <p class="mt-1.5 text-sm text-slate-400">
                {{ $t('Zero-downtime deploys for your Laravel projects.') }}
            </p>
        </div>

        <form class="mt-8 space-y-5" @submit.prevent="submit">
            <div>
                <label
                    for="login-email"
                    class="mb-1.5 block text-sm font-medium text-slate-300"
                >
                    {{ $t('Email') }}
                </label>
                <div class="relative">
                    <Mail
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500"
                    />
                    <input
                        id="login-email"
                        v-model="form.email"
                        v-focus
                        type="email"
                        autocomplete="username"
                        required
                        class="pr-3"
                        :class="[
                            inputClass,
                            form.errors.email ? errorClass : '',
                        ]"
                        placeholder="you@example.com"
                    />
                </div>
                <p
                    v-if="form.errors.email"
                    class="mt-1.5 text-xs font-medium text-rose-400"
                >
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <label
                    for="login-password"
                    class="mb-1.5 block text-sm font-medium text-slate-300"
                >
                    {{ $t('Password') }}
                </label>
                <div class="relative">
                    <LockKeyhole
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500"
                    />
                    <input
                        id="login-password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        class="pr-10"
                        :class="[
                            inputClass,
                            form.errors.password ? errorClass : '',
                        ]"
                    />
                    <button
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 rounded-md p-1 text-slate-500 transition hover:text-slate-300"
                        :aria-label="
                            showPassword
                                ? $t('Hide password')
                                : $t('Show password')
                        "
                        @click="showPassword = !showPassword"
                    >
                        <EyeOff v-if="showPassword" class="size-4" />
                        <Eye v-else class="size-4" />
                    </button>
                </div>
                <p
                    v-if="form.errors.password"
                    class="mt-1.5 text-xs font-medium text-rose-400"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <label
                class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-300 select-none"
            >
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="size-4 rounded border-white/20 bg-white/5 accent-brand-500"
                />
                {{ $t('Remember me') }}
            </label>

            <AppButton
                type="submit"
                class="w-full"
                :icon="LogIn"
                :loading="form.processing"
                >{{ $t('Log in') }}</AppButton
            >
        </form>
    </div>

    <p
        class="mt-6 flex items-center justify-center gap-1.5 text-center text-xs text-slate-500"
    >
        <ShieldCheck class="size-3.5" />
        {{
            $t(
                'Releases side by side, switched in only when every step worked.',
            )
        }}
    </p>
</template>

<script lang="ts">
import {
    Eye,
    EyeOff,
    LockKeyhole,
    LogIn,
    Mail,
    Rocket,
    ShieldCheck,
} from '@lucide/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { defineComponent, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import loginRoutes from '@/routes/login';

/** Sign-in form on the dark auth layout. */
export default defineComponent({
    name: 'AuthLogin',

    components: {
        AppButton,
        Eye,
        EyeOff,
        Head,
        LockKeyhole,
        Mail,
        Rocket,
        ShieldCheck,
    },

    data() {
        return {
            showPassword: false,
            LogIn: markRaw(LogIn),
            inputClass:
                'block w-full rounded-lg border border-white/10 bg-white/5 py-2.5 pl-9 text-sm text-white shadow-inner shadow-black/20 transition outline-none placeholder:text-slate-500 focus:border-brand-400 focus:bg-white/[0.07] focus:ring-2 focus:ring-brand-500/30',
            errorClass:
                'border-rose-400/60 focus:border-rose-400 focus:ring-rose-500/30',
            form: useForm({
                email: '',
                password: '',
                remember: false,
            }),
        };
    },

    methods: {
        submit(): void {
            this.form.submit(loginRoutes.store(), {
                onFinish: () => this.form.reset('password'),
            });
        },
    },
});
</script>
