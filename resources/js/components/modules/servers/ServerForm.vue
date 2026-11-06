<template>
    <form class="space-y-8" @submit.prevent="submit">
        <!-- Name + how AutoDeploy reaches it -->
        <FormSection
            :title="$t('Server')"
            :description="
                $t(
                    'A name you recognise, and how AutoDeploy reaches the machine.',
                )
            "
            :icon="ServerCog"
        >
            <TextInput
                v-model="form.name"
                :label="$t('Name')"
                :placeholder="$t('e.g. Production VPS')"
                :error="form.errors.name"
                required
            />

            <div>
                <span class="form-label">{{ $t('Connection') }}</span>
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    <button
                        v-for="type in connectionChoices"
                        :key="type.value"
                        type="button"
                        class="relative flex gap-3 rounded-lg border p-3.5 text-left transition"
                        :class="
                            form.connection_type === type.value
                                ? 'border-brand-500 bg-brand-50/60 ring-4 ring-brand-100'
                                : 'border-slate-200 hover:border-slate-300'
                        "
                        :aria-pressed="form.connection_type === type.value"
                        @click="form.connection_type = type.value"
                    >
                        <span
                            class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-md"
                            :class="
                                form.connection_type === type.value
                                    ? 'bg-brand-600 text-white'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            <component :is="type.icon" class="size-[18px]" />
                        </span>
                        <span class="min-w-0">
                            <span
                                class="block text-sm font-semibold text-slate-900"
                                >{{ type.label }}</span
                            >
                            <span
                                class="mt-0.5 block text-xs leading-relaxed text-slate-500"
                                >{{ type.description }}</span
                            >
                        </span>
                    </button>
                </div>
                <p v-if="form.errors.connection_type" class="form-error">
                    {{ form.errors.connection_type }}
                </p>
            </div>

            <div
                v-if="isLocal"
                class="flex items-start gap-3 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-100 ring-inset"
            >
                <Info class="mt-0.5 size-4 shrink-0 text-sky-600" />
                <div class="space-y-1">
                    <p>
                        {{
                            $t(
                                'The deploy commands run directly on this computer, as the user that runs AutoDeploy (the web server and the queue worker). No SSH needed.',
                            )
                        }}
                    </p>
                    <p v-if="isWindows" class="text-sky-800/80">
                        {{
                            $t(
                                'This computer runs Windows: the commands run in Git Bash. Handy for trying things out; a real server should be Linux.',
                            )
                        }}
                    </p>
                </div>
            </div>
        </FormSection>

        <!-- SSH access -->
        <FormSection
            v-if="!isLocal"
            :title="$t('SSH access')"
            :description="
                $t(
                    'AutoDeploy logs in the way you do with ssh user@host. Use a user that can write to the deploy folder and reload PHP-FPM (often root).',
                )
            "
            :icon="KeyRound"
        >
            <template #aside>
                <p
                    class="mt-3 hidden text-xs leading-relaxed text-slate-500 lg:block"
                >
                    {{
                        $t(
                            'The first connection saves the host key of the server; later connections are refused if it changes.',
                        )
                    }}
                </p>
            </template>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <TextInput
                    v-model="form.host"
                    class="sm:col-span-2"
                    :label="$t('Host')"
                    placeholder="203.0.113.10"
                    :hint="$t('IP address or domain name')"
                    :error="form.errors.host"
                    input-class="font-mono"
                    required
                />
                <TextInput
                    v-model="form.port"
                    type="number"
                    :label="$t('Port')"
                    min="1"
                    max="65535"
                    :error="form.errors.port"
                    input-class="font-mono"
                    required
                />
                <TextInput
                    v-model="form.username"
                    :label="$t('User')"
                    placeholder="root"
                    :error="form.errors.username"
                    input-class="font-mono"
                    required
                />
            </div>

            <!-- How to log in -->
            <div>
                <span class="form-label">{{ $t('Log in with') }}</span>
                <div
                    class="grid grid-cols-1 gap-1 rounded-lg bg-slate-100 p-1 sm:grid-cols-3"
                    role="radiogroup"
                >
                    <button
                        v-for="method in authChoices"
                        :key="method.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.auth_method === method.value"
                        class="flex items-center justify-center gap-2 rounded-md px-3 py-2 text-center text-sm font-medium transition"
                        :class="
                            form.auth_method === method.value
                                ? 'bg-white text-brand-700 shadow-sm ring-1 ring-slate-200'
                                : 'text-slate-600 hover:text-slate-900'
                        "
                        @click="form.auth_method = method.value"
                    >
                        <component :is="method.icon" class="size-4" />
                        {{ method.label }}
                    </button>
                </div>
                <p v-if="form.errors.auth_method" class="form-error">
                    {{ form.errors.auth_method }}
                </p>
            </div>

            <!-- Key file -->
            <TextInput
                v-if="form.auth_method === 'key_file'"
                v-model="form.key_path"
                :label="$t('Private key file')"
                :placeholder="defaultKeyPath"
                :hint="keyPathHint"
                :error="form.errors.key_path"
                input-class="font-mono"
                required
            />

            <!-- Pasted key -->
            <div v-if="form.auth_method === 'key'">
                <label for="server-private-key" class="form-label">
                    {{ $t('Private key') }}
                    <span v-if="!server?.has_private_key" class="text-rose-500"
                        >*</span
                    >
                </label>
                <SavedSecretNote
                    v-if="server?.has_private_key"
                    :text="$t('A key is saved. Leave empty to keep it.')"
                />
                <textarea
                    id="server-private-key"
                    v-model="form.private_key"
                    rows="7"
                    spellcheck="false"
                    autocomplete="off"
                    class="form-control resize-y font-mono text-xs leading-relaxed"
                    :class="{ 'form-control-error': form.errors.private_key }"
                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                />
                <p v-if="form.errors.private_key" class="form-error">
                    {{ form.errors.private_key }}
                </p>
                <p v-else class="form-hint">
                    {{
                        $t(
                            'Paste the whole private key, including the BEGIN and END lines. It is stored encrypted.',
                        )
                    }}
                </p>
            </div>

            <!-- Passphrase for both key methods -->
            <div v-if="form.auth_method !== 'password'">
                <TextInput
                    v-model="form.passphrase"
                    type="password"
                    :label="$t('Key passphrase (optional)')"
                    :placeholder="server?.has_passphrase ? '••••••••' : ''"
                    :hint="
                        server?.has_passphrase
                            ? $t(
                                  'A passphrase is saved. Leave empty to keep it.',
                              )
                            : $t(
                                  'Only if the key is protected with a passphrase.',
                              )
                    "
                    :error="form.errors.passphrase"
                    autocomplete="new-password"
                />
            </div>

            <!-- Password -->
            <div v-if="form.auth_method === 'password'">
                <TextInput
                    v-model="form.password"
                    type="password"
                    :label="$t('Password')"
                    :placeholder="server?.has_password ? '••••••••' : ''"
                    :hint="
                        server?.has_password
                            ? $t('A password is saved. Leave empty to keep it.')
                            : $t(
                                  'Stored encrypted. A key is safer than a password.',
                              )
                    "
                    :error="form.errors.password"
                    autocomplete="new-password"
                    :required="!server?.has_password"
                />
            </div>
        </FormSection>

        <!-- Save -->
        <div
            class="sticky bottom-4 z-20 flex flex-col-reverse gap-2 rounded-xl border border-slate-200 bg-white/90 px-4 py-3 shadow-lg shadow-slate-900/5 backdrop-blur sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-xs text-slate-500">
                {{
                    server
                        ? $t(
                              'Saving does not connect; use Test connection on the server page.',
                          )
                        : $t(
                              'After saving, AutoDeploy connects once and reads RAM, disk and installed tools.',
                          )
                }}
            </p>
            <div class="flex shrink-0 items-center justify-end gap-2">
                <AppButton :href="cancelHref" variant="ghost">{{
                    $t('Cancel')
                }}</AppButton>
                <AppButton
                    type="submit"
                    :icon="server ? Save : PlugZap"
                    :loading="form.processing"
                    >{{
                        server ? $t('Save changes') : $t('Save and connect')
                    }}</AppButton
                >
            </div>
        </div>
    </form>
</template>

<script lang="ts">
import {
    FileKey,
    Info,
    KeyRound,
    Laptop,
    LockKeyhole,
    PlugZap,
    Save,
    ScrollText,
    ServerCog,
    Globe,
} from '@lucide/vue';
import { useForm } from '@inertiajs/vue3';
import type { Component, PropType } from 'vue';
import { defineComponent, h, markRaw } from 'vue';
import AppButton from '@/components/buttons/AppButton.vue';
import TextInput from '@/components/inputs/TextInput.vue';
import FormSection from '@/components/modules/forms/FormSection.vue';
import serverRoutes from '@/routes/servers';
import type { Option, Server } from '@/types';

type ConnectionType = Server['connection_type'];
type AuthMethod = Server['auth_method'];

/** "Saved" line above an empty secret field. */
const SavedSecretNote = defineComponent({
    name: 'SavedSecretNote',
    props: { text: { type: String, required: true } },
    setup(props) {
        return () =>
            h(
                'p',
                {
                    class: 'mb-2 inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-100 ring-inset',
                },
                [h(LockKeyhole, { class: 'size-3.5' }), props.text],
            );
    },
});

/**
 * Add / edit a server: SSH or this machine, host, user and how to log in.
 * On edit the secret fields start empty: empty = keep the saved one.
 */
export default defineComponent({
    name: 'ServerForm',

    components: { AppButton, FormSection, Info, SavedSecretNote, TextInput },

    props: {
        /** The server being edited (null = new server). */
        server: { type: Object as PropType<Server | null>, default: null },
        connectionTypes: {
            type: Array as PropType<Option[]>,
            default: () => [],
        },
        authMethods: { type: Array as PropType<Option[]>, default: () => [] },
        defaultKeyPath: { type: String, default: '~/.ssh/id_ed25519' },
        isWindows: { type: Boolean, default: false },
    },

    data() {
        const server = this.server;

        return {
            KeyRound: markRaw(KeyRound),
            PlugZap: markRaw(PlugZap),
            Save: markRaw(Save),
            ServerCog: markRaw(ServerCog),
            form: useForm({
                name: server?.name ?? '',
                connection_type: (server?.connection_type ??
                    'ssh') as ConnectionType,
                host: server?.host ?? '',
                port: (server && !server.is_local ? server.port : 22) as
                    | number
                    | null,
                username: server && !server.is_local ? server.username : 'root',
                auth_method: (server?.auth_method ?? 'key_file') as AuthMethod,
                key_path: server?.key_path || this.defaultKeyPath,
                private_key: '',
                passphrase: '',
                password: '',
            }),
        };
    },

    computed: {
        isLocal(): boolean {
            return this.form.connection_type === 'local';
        },

        connectionChoices(): {
            value: ConnectionType;
            label: string;
            description: string;
            icon: Component;
        }[] {
            return [
                {
                    value: 'ssh',
                    label: this.optionLabel(
                        this.connectionTypes,
                        'ssh',
                        this.$t('Remote server (SSH)'),
                    ),
                    description: this.$t(
                        'A VPS (DigitalOcean, Hetzner…) that AutoDeploy reaches over SSH from this computer.',
                    ),
                    icon: markRaw(Globe),
                },
                {
                    value: 'local',
                    label: this.optionLabel(
                        this.connectionTypes,
                        'local',
                        this.$t('This machine'),
                    ),
                    description: this.$t(
                        'AutoDeploy is installed on the server itself and deploys to the machine it runs on.',
                    ),
                    icon: markRaw(Laptop),
                },
            ];
        },

        authChoices(): { value: AuthMethod; label: string; icon: Component }[] {
            return [
                {
                    value: 'key_file',
                    label: this.optionLabel(
                        this.authMethods,
                        'key_file',
                        this.$t('Key file on this computer'),
                    ),
                    icon: markRaw(FileKey),
                },
                {
                    value: 'key',
                    label: this.optionLabel(
                        this.authMethods,
                        'key',
                        this.$t('Paste a private key'),
                    ),
                    icon: markRaw(ScrollText),
                },
                {
                    value: 'password',
                    label: this.optionLabel(
                        this.authMethods,
                        'password',
                        this.$t('Password'),
                    ),
                    icon: markRaw(LockKeyhole),
                },
            ];
        },

        keyPathHint(): string {
            return this.$t(
                'Path on the computer running AutoDeploy, e.g. :example. The matching .pub key must be in ~/.ssh/authorized_keys on the server.',
                {
                    example: this.isWindows
                        ? 'C:\\Users\\you\\.ssh\\id_ed25519'
                        : '~/.ssh/id_ed25519',
                },
            );
        },

        cancelHref(): string {
            return this.server
                ? serverRoutes.show(this.server.id).url
                : serverRoutes.index().url;
        },
    },

    methods: {
        optionLabel(
            options: Option[],
            value: string,
            fallback: string,
        ): string {
            return (
                options.find((option) => option.value === value)?.label ??
                fallback
            );
        },

        submit(): void {
            const route = this.server
                ? serverRoutes.update(this.server.id)
                : serverRoutes.store();

            this.form.submit(route, {
                preserveScroll: true,
                onSuccess: () =>
                    this.form.reset('private_key', 'passphrase', 'password'),
                onError: () => this.revealFirstError(),
            });
        },

        revealFirstError(): void {
            this.$nextTick(() =>
                document
                    .querySelector('.form-error')
                    ?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
            );
        },
    },
});
</script>
