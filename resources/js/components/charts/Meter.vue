<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { SERIES, STATUS, isDark } from './tokens';

/**
 * A single ratio against a limit.
 *
 * The data-viz guidance is explicit that this is a meter, not a chart — a
 * two-slice pie or a one-bar bar chart for "78% adherence" is strictly worse at
 * the same job.
 */
const props = defineProps({
    value: { type: Number, default: null },   // 0..100
    label: { type: String, required: true },
    /** Below this, the reading is clinically concerning. */
    threshold: { type: Number, default: null },
    thresholdLabel: { type: String, default: '' },
});

const { n } = useI18n();
const dark = computed(() => isDark());

const has = computed(() => props.value !== null && Number.isFinite(props.value));
const clamped = computed(() => Math.max(0, Math.min(100, props.value ?? 0)));

// Status colour only when a threshold defines what "bad" means. Without one
// there is no basis for colouring the value, so it stays the neutral series hue.
const colour = computed(() => {
    if (!has.value) return dark.value ? SERIES.dark : SERIES.light;
    if (props.threshold === null) return dark.value ? SERIES.dark : SERIES.light;
    return clamped.value >= props.threshold ? STATUS.good : STATUS.critical;
});

const below = computed(() =>
    has.value && props.threshold !== null && clamped.value < props.threshold
);
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-3">
            <span class="text-sm text-slate-700 dark:text-slate-300">{{ label }}</span>
            <span class="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">
                {{ has ? `${n(clamped)}%` : '—' }}
            </span>
        </div>

        <div
            class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
            role="meter"
            :aria-valuenow="has ? clamped : undefined"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label"
        >
            <div
                class="h-full rounded-full transition-[width] duration-500"
                :style="{ width: `${clamped}%`, backgroundColor: colour }"
            />
        </div>

        <!-- Status is never colour alone: when the reading is below target it
             says so in words as well. -->
        <p v-if="below" class="mt-1.5 flex items-center gap-1 text-xs text-red-700 dark:text-red-400">
            <UIcon name="i-lucide-alert-triangle" class="size-3 shrink-0" />
            {{ thresholdLabel }}
        </p>
    </div>
</template>
