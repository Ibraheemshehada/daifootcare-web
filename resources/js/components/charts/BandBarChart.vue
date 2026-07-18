<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ORDINAL, isDark } from './tokens';

/**
 * Horizontal bars for an ordered set of bands (SUS excellent → poor).
 *
 * Ordered categories are a *sequential* job, not a categorical one: one hue,
 * light→dark, so the reader sees rank in the colour rather than having to learn
 * an arbitrary key. Horizontal because the band names are words.
 */
const props = defineProps({
    /** [{ key, label, value }] in rank order, best first. */
    bands: { type: Array, default: () => [] },
    benchmark: { type: Number, default: null },
    benchmarkLabel: { type: String, default: '' },
});

const { n } = useI18n();
const dark = computed(() => isDark());
const ramp = computed(() => (dark.value ? ORDINAL.dark : ORDINAL.light));

const total = computed(() => props.bands.reduce((s, b) => s + (b.value || 0), 0));
const pct = (v) => (total.value ? (v / total.value) * 100 : 0);
</script>

<template>
    <div>
        <ul class="space-y-3">
            <li v-for="(b, i) in bands" :key="b.key" class="flex items-center gap-3">
                <!-- Direct label, always. Colour alone never carries the identity
                     of a band, and this is what the CVD floor requires. -->
                <span class="w-24 shrink-0 text-sm text-slate-700 dark:text-slate-300">{{ b.label }}</span>

                <div class="h-3 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div
                        class="h-full rounded-full transition-[width] duration-500"
                        :style="{ width: `${pct(b.value)}%`, backgroundColor: ramp[i] ?? ramp[ramp.length - 1] }"
                    />
                </div>

                <span class="w-14 shrink-0 text-end text-sm tabular-nums text-slate-700 dark:text-slate-300">
                    {{ n(b.value) }}<span v-if="total" class="text-slate-500"> · {{ pct(b.value).toFixed(0) }}%</span>
                </span>
            </li>
        </ul>

        <p v-if="benchmark !== null" class="mt-3 text-xs text-slate-600 dark:text-slate-400">
            {{ benchmarkLabel }}
        </p>
    </div>
</template>
