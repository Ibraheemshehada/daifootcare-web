<script setup>
import { computed } from 'vue';

const props = defineProps({
    values: { type: Array, default: () => [] },
    stroke: { type: String, default: 'currentColor' },
    height: { type: Number, default: 64 },
});

const W = 280;

/**
 * Guards the degenerate cases before they reach the path maths.
 *
 * A flat series (every value equal, including all-zero) gives a zero span, and
 * dividing by it yields NaN/Infinity coordinates — the same class of bug that
 * crashed the mobile app's healing chart when every wound area was 0. Here it
 * would render an invisible or broken path instead, so the span is forced to a
 * finite minimum and a flat series is drawn as a flat line.
 */
const path = computed(() => {
    const vals = props.values.filter((v) => Number.isFinite(v));
    if (vals.length === 0) return null;
    if (vals.length === 1) return `M0,${props.height / 2} L${W},${props.height / 2}`;

    const min = Math.min(...vals);
    const max = Math.max(...vals);
    const span = max - min || 1;
    const pad = 4;
    const h = props.height - pad * 2;

    return vals
        .map((v, i) => {
            const x = (i / (vals.length - 1)) * W;
            const y = pad + h - ((v - min) / span) * h;
            return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});

const last = computed(() => {
    const vals = props.values.filter((v) => Number.isFinite(v));
    return vals.length ? vals[vals.length - 1] : null;
});
</script>

<template>
    <div>
        <svg
            v-if="path"
            :viewBox="`0 0 ${W} ${height}`"
            :height="height"
            class="w-full"
            role="img"
            :aria-label="`Trend, latest value ${last}`"
            preserveAspectRatio="none"
        >
            <path :d="path" fill="none" :stroke="stroke" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
        </svg>
        <p v-else class="py-4 text-sm text-slate-600 dark:text-slate-400">—</p>
    </div>
</template>
