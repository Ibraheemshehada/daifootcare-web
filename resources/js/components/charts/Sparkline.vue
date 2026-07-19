<script setup>
import { computed } from 'vue';

/**
 * A trend shape, without axes or labels.
 *
 * Used inside cards where the direction is the message and the exact values are
 * one click away. Anything that needs to be read precisely gets a real chart.
 */
const props = defineProps({
    values: { type: Array, default: () => [] },
    stroke: { type: String, default: 'currentColor' },
    height: { type: Number, default: 36 },
});

const W = 120;

const clean = computed(() => props.values.filter((v) => Number.isFinite(v)));

/**
 * A flat series (every value equal, including all-zero) has a zero span, and
 * dividing by it yields NaN coordinates and an invisible path — the same class
 * of bug that crashed the mobile healing chart when every wound area was 0. A
 * flat series is drawn as a flat line instead.
 */
const path = computed(() => {
    const v = clean.value;
    if (v.length < 2) return null;

    const min = Math.min(...v);
    const max = Math.max(...v);
    const span = max - min;
    const pad = 3;
    const h = props.height - pad * 2;

    return v
        .map((y, i) => {
            const x = (i / (v.length - 1)) * W;
            // Zero span means every point sits on the midline rather than at NaN.
            const yy = span < 1e-9 ? pad + h / 2 : pad + h - ((y - min) / span) * h;
            return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${yy.toFixed(1)}`;
        })
        .join(' ');
});

/** Falling wound area is improvement; the label says so rather than implying it. */
const direction = computed(() => {
    const v = clean.value;
    if (v.length < 2) return null;
    return v[v.length - 1] < v[0] ? 'falling' : v[v.length - 1] > v[0] ? 'rising' : 'flat';
});
</script>

<template>
    <svg
        v-if="path"
        :viewBox="`0 0 ${W} ${height}`"
        :height="height"
        class="w-full"
        role="img"
        :aria-label="`Trend ${direction}, ${clean.length} points`"
        preserveAspectRatio="none"
    >
        <path
            :d="path" fill="none" :stroke="stroke" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
    <div v-else :style="{ height: `${height}px` }" />
</template>
