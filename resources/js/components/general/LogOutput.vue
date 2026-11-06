<template>
    <div
        class="relative overflow-hidden rounded-lg bg-slate-950 ring-1 ring-slate-800"
    >
        <div
            v-if="title || copyable"
            class="flex items-center justify-between gap-3 border-b border-white/5 px-4 py-2"
        >
            <span class="truncate font-mono text-xs text-slate-400">{{
                title
            }}</span>
            <CopyButton
                v-if="copyable && text"
                :text="plain"
                dark
                class="shrink-0"
            />
        </div>
        <pre
            ref="pre"
            class="overflow-auto px-4 py-3 font-mono text-[12.5px] leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap text-slate-200"
            :class="maxHeightClass"
            @scroll="onScroll"
        ><code v-if="text" v-html="html" /><span v-else class="text-slate-500">{{ empty || $t('No output.') }}</span><span v-if="live" class="ml-0.5 inline-block h-3.5 w-1.5 translate-y-0.5 animate-pulse bg-slate-400" /></pre>
    </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import CopyButton from '@/components/general/CopyButton.vue';
import { ansiToHtml, ansiToText } from '@/utils/ansi';

/** Dark terminal box with colored output. Follows new lines while "live" (unless scrolled up). */
export default defineComponent({
    name: 'LogOutput',

    components: { CopyButton },

    props: {
        text: { type: String, default: '' },
        title: { type: String, default: '' },
        empty: { type: String, default: '' },
        live: { type: Boolean, default: false },
        copyable: { type: Boolean, default: true },
        /** Tailwind max-height class, e.g. "max-h-96". Empty = no limit. */
        maxHeight: { type: String, default: 'max-h-[28rem]' },
    },

    data() {
        return {
            stickToBottom: true,
        };
    },

    computed: {
        html(): string {
            return ansiToHtml(this.text);
        },

        plain(): string {
            return ansiToText(this.text);
        },

        maxHeightClass(): string {
            return this.maxHeight;
        },
    },

    watch: {
        text() {
            if (this.live && this.stickToBottom) {
                this.$nextTick(() => this.scrollToBottom());
            }
        },
    },

    mounted() {
        if (this.live) {
            this.scrollToBottom();
        }
    },

    methods: {
        scrollToBottom(): void {
            const pre = this.$refs.pre as HTMLElement | undefined;

            if (pre) {
                pre.scrollTop = pre.scrollHeight;
            }
        },

        onScroll(): void {
            const pre = this.$refs.pre as HTMLElement;
            this.stickToBottom =
                pre.scrollHeight - pre.scrollTop - pre.clientHeight < 40;
        },
    },
});
</script>
