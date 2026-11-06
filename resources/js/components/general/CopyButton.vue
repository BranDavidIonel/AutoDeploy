<template>
    <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium transition"
        :class="
            dark
                ? 'text-slate-400 hover:bg-white/10 hover:text-white'
                : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'
        "
        :title="$t('Copy')"
        @click="copy"
    >
        <Check v-if="copied" class="size-3.5 text-emerald-500" />
        <Copy v-else class="size-3.5" />
        <span v-if="label || copied">{{ copied ? $t('Copied') : label }}</span>
    </button>
</template>

<script lang="ts">
import { Check, Copy } from '@lucide/vue';
import { defineComponent } from 'vue';

/** Copies a text to the clipboard and says so for a moment. */
export default defineComponent({
    name: 'CopyButton',

    components: { Check, Copy },

    props: {
        text: { type: String, required: true },
        label: { type: String, default: '' },
        dark: { type: Boolean, default: false },
    },

    data() {
        return { copied: false };
    },

    methods: {
        async copy(): Promise<void> {
            try {
                await navigator.clipboard.writeText(this.text);
            } catch {
                // Plain http on a LAN address has no clipboard API: fall back.
                const area = document.createElement('textarea');
                area.value = this.text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }

            this.copied = true;
            setTimeout(() => (this.copied = false), 1500);
        },
    },
});
</script>
