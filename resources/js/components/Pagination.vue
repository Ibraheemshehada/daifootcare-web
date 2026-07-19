<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Page controls for a Laravel paginator.
 *
 * Every list endpoint paginates and nothing consumed it, so past the first page
 * the data was simply unreachable — and unreachable without any sign it existed,
 * which reads as "there is no more data" rather than "there is a broken
 * control". That is the worst way for a feature to be missing.
 */
const props = defineProps({
    /** The paginator envelope: { current_page, last_page, total, per_page } */
    meta: { type: Object, default: null },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);
const { t, n } = useI18n();

const current = computed(() => props.meta?.current_page ?? 1);
const last = computed(() => props.meta?.last_page ?? 1);
const total = computed(() => props.meta?.total ?? 0);

const from = computed(() => {
    if (!total.value) return 0;
    return (current.value - 1) * (props.meta?.per_page ?? 0) + 1;
});
const to = computed(() => Math.min(current.value * (props.meta?.per_page ?? 0), total.value));

/**
 * A compact window around the current page, with ellipses.
 *
 * Rendering every page number is fine at 5 pages and unusable at 200 — and a
 * study that runs for months will get there.
 */
const pages = computed(() => {
    const out = [];
    const span = 1;
    for (let i = 1; i <= last.value; i++) {
        const near = Math.abs(i - current.value) <= span;
        if (i === 1 || i === last.value || near) {
            out.push(i);
        } else if (out[out.length - 1] !== '…') {
            out.push('…');
        }
    }
    return out;
});

function go(page) {
    if (page === '…' || page === current.value || page < 1 || page > last.value) return;
    emit('change', page);
}
</script>

<template>
    <nav
        v-if="meta && last > 1"
        class="mt-6 flex flex-wrap items-center justify-between gap-3"
        :aria-label="t('pagination.label')"
    >
        <!-- Said in words as well as controls: "showing 25 of 240" is the part
             that tells a clinician there is more to see. -->
        <p class="text-sm text-slate-600 dark:text-slate-400">
            {{ t('pagination.showing', { from: n(from), to: n(to), total: n(total) }) }}
        </p>

        <div class="flex items-center gap-1">
            <UButton
                icon="i-lucide-chevron-left"
                color="neutral" variant="ghost" size="sm"
                class="rtl-flip"
                :disabled="current === 1 || loading"
                :aria-label="t('pagination.previous')"
                @click="go(current - 1)"
            />

            <button
                v-for="(p, i) in pages" :key="`${p}-${i}`"
                type="button"
                class="min-w-9 rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors"
                :class="p === current
                    ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                    : p === '…'
                        ? 'cursor-default text-slate-400'
                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                :disabled="p === '…' || loading"
                :aria-current="p === current ? 'page' : undefined"
                :aria-label="p === '…' ? undefined : t('pagination.go_to', { n: p })"
                @click="go(p)"
            >
                {{ p }}
            </button>

            <UButton
                icon="i-lucide-chevron-right"
                color="neutral" variant="ghost" size="sm"
                class="rtl-flip"
                :disabled="current === last || loading"
                :aria-label="t('pagination.next')"
                @click="go(current + 1)"
            />
        </div>
    </nav>
</template>
