<template>
    <div class="overflow-hidden rounded-lg bg-slate-950 ring-1 ring-slate-800">
        <div
            class="flex items-center justify-between gap-3 border-b border-white/5 px-4 py-2"
        >
            <span
                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                {{ $t('What AutoDeploy creates on the server') }}
            </span>
        </div>
        <pre
            class="overflow-x-auto px-4 py-3 font-mono text-[12px] leading-relaxed text-slate-300"
        ><span class="text-white">{{ base }}</span>
<span class="text-slate-600">├─</span> <span class="text-sky-300">releases/</span>          <span class="text-slate-500"># {{ $t('one folder per deployment') }}</span>
<span class="text-slate-600">│  └─</span> <span class="text-sky-300/80">20261106120000/</span>
<span class="text-slate-600">├─</span> <span class="text-amber-300">shared/</span>            <span class="text-slate-500"># {{ sharedText }}</span>
<span class="text-slate-600">└─</span> <span class="text-emerald-300">current</span> <span class="text-slate-500">→</span> releases/20261106120000</pre>
        <div
            class="flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-white/5 bg-white/[0.03] px-4 py-2.5 text-xs"
        >
            <span class="text-slate-400">{{ $t('Nginx root:') }}</span>
            <code class="min-w-0 font-mono break-all text-emerald-300">{{
                publicPath
            }}</code>
            <CopyButton :text="publicPath" dark class="ml-auto" />
        </div>
    </div>
</template>

<script lang="ts">
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import CopyButton from '@/components/general/CopyButton.vue';

/** Small tree of the deploy folder: releases/, shared/, current → and the Nginx root to use. */
export default defineComponent({
    name: 'PathPreview',

    components: { CopyButton },

    props: {
        deployPath: { type: String, default: '' },
        shared: { type: Array as PropType<string[]>, default: () => [] },
    },

    computed: {
        base(): string {
            return (
                (this.deployPath || '/var/www/my-app').replace(/\/+$/, '') ||
                '/'
            );
        },

        publicPath(): string {
            return `${this.base}/current/public`;
        },

        sharedText(): string {
            return this.shared.length
                ? this.$t('kept between releases: :items', {
                      items:
                          this.shared.slice(0, 4).join(', ') +
                          (this.shared.length > 4 ? '…' : ''),
                  })
                : this.$t('kept between releases');
        },
    },
});
</script>
