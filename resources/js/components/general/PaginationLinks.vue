<template>
    <div
        v-if="meta.total > 0"
        class="flex flex-col items-center justify-between gap-3 px-5 py-4 text-sm text-slate-500 sm:flex-row"
    >
        <p>
            {{
                $t('Showing :from–:to of :total', {
                    from: meta.from ?? 0,
                    to: meta.to ?? 0,
                    total: meta.total,
                })
            }}
        </p>
        <!-- Phones: only arrows + "3 / 11"; page numbers from sm up, wrapping if needed. -->
        <nav
            v-if="meta.last_page > 1"
            class="flex flex-wrap items-center justify-center gap-1"
        >
            <template v-for="(link, index) in meta.links" :key="index">
                <span
                    v-if="!link.url"
                    class="min-w-9 items-center justify-center rounded-lg px-2.5 py-1.5 text-slate-300"
                    :class="displayClass(index)"
                >
                    <ChevronLeft v-if="index === 0" class="size-4" />
                    <ChevronRight
                        v-else-if="index === meta.links.length - 1"
                        class="size-4"
                    />
                    <template v-else>…</template>
                </span>
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="min-w-9 items-center justify-center rounded-lg px-2.5 py-1.5 font-medium transition"
                    :class="[
                        displayClass(index),
                        link.active
                            ? 'bg-brand-600 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-slate-100',
                    ]"
                >
                    <ChevronLeft v-if="index === 0" class="size-4" />
                    <ChevronRight
                        v-else-if="index === meta.links.length - 1"
                        class="size-4"
                    />
                    <template v-else>{{ link.label }}</template>
                </Link>
                <span
                    v-if="index === 0"
                    class="px-2 font-medium whitespace-nowrap text-slate-600 sm:hidden"
                >
                    {{ meta.current_page }} / {{ meta.last_page }}
                </span>
            </template>
        </nav>
    </div>
</template>

<script lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import type { Paginated } from '@/types';

/** Pagination for Laravel paginated resources ("meta" block). */
export default defineComponent({
    name: 'PaginationLinks',

    components: { ChevronLeft, ChevronRight, Link },

    props: {
        meta: {
            type: Object as PropType<Paginated<unknown>['meta']>,
            required: true,
        },
    },

    methods: {
        /** The arrows always show; page numbers and "…" only from sm up. */
        displayClass(index: number): string {
            const isArrow = index === 0 || index === this.meta.links.length - 1;

            return isArrow ? 'flex' : 'hidden sm:flex';
        },
    },
});
</script>
