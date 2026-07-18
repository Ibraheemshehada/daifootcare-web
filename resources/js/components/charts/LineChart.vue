<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { SERIES, AXIS, isDark } from './tokens';

const props = defineProps({
    /** [{ x: Date|string, y: number }] — oldest first. */
    points: { type: Array, default: () => [] },
    unit: { type: String, default: '' },
    /** Optional healthy band drawn behind the line, e.g. glucose 70–130. */
    band: { type: Object, default: null },
    /**
     * Whether a change is good news. 'lower' = wound area, 'higher' = e.g. a
     * score. `null` means the direction carries no clinical verdict — glucose
     * moving down is not automatically good — and no delta badge is shown.
     */
    better: { type: String, default: null },   // 'lower' | 'higher' | null
    /** Quantities that cannot be negative clamp the axis at zero. */
    nonNegative: { type: Boolean, default: true },
    height: { type: Number, default: 220 },
    label: { type: String, default: '' },
});

const { d, n } = useI18n();

const W = 640;
const PAD = { top: 16, right: 16, bottom: 28, left: 44 };

const dark = computed(() => isDark());
const stroke = computed(() => (dark.value ? SERIES.dark : SERIES.light));
const axis = computed(() => (dark.value ? AXIS.dark : AXIS.light));

const clean = computed(() =>
    props.points
        .map((p) => ({ x: new Date(p.x), y: Number(p.y) }))
        .filter((p) => Number.isFinite(p.y) && !Number.isNaN(p.x.getTime()))
);

/**
 * Y bounds.
 *
 * A flat series (every value equal, including all-zero) has a zero span, and
 * dividing by it produces NaN/Infinity coordinates — the bug that crashed the
 * mobile healing chart when every wound area was 0. The span is forced to a
 * finite minimum so a flat series draws as a flat line instead.
 */
const bounds = computed(() => {
    const ys = clean.value.map((p) => p.y);
    if (props.band) ys.push(props.band.from, props.band.to);
    if (!ys.length) return { min: 0, max: 1 };

    let min = Math.min(...ys);
    let max = Math.max(...ys);
    if (max - min < 1e-6) {
        const pad = Math.abs(max) * 0.1 || 1;
        min -= pad;
        max += pad;
    }
    const headroom = (max - min) * 0.1;
    // An area or a concentration cannot be negative; letting the padded axis dip
    // below zero invites reading a floor that does not exist.
    const lo = min - headroom;
    return {
        min: props.nonNegative && min >= 0 ? Math.max(0, lo) : lo,
        max: max + headroom,
    };
});

const plotW = W - PAD.left - PAD.right;
const plotH = computed(() => props.height - PAD.top - PAD.bottom);

const sx = (i) => (clean.value.length < 2
    ? PAD.left + plotW / 2
    : PAD.left + (i / (clean.value.length - 1)) * plotW);

const sy = (v) => {
    const { min, max } = bounds.value;
    return PAD.top + plotH.value - ((v - min) / (max - min)) * plotH.value;
};

const linePath = computed(() =>
    clean.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${sx(i).toFixed(1)},${sy(p.y).toFixed(1)}`).join(' ')
);

const areaPath = computed(() => {
    if (clean.value.length < 2) return null;
    const top = clean.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${sx(i).toFixed(1)},${sy(p.y).toFixed(1)}`).join(' ');
    return `${top} L${sx(clean.value.length - 1).toFixed(1)},${(PAD.top + plotH.value).toFixed(1)} L${sx(0).toFixed(1)},${(PAD.top + plotH.value).toFixed(1)} Z`;
});

/** Four gridlines is enough to read a value without becoming furniture. */
const ticks = computed(() => {
    const { min, max } = bounds.value;
    return [0, 1, 2, 3].map((i) => {
        const v = min + ((max - min) * i) / 3;
        return { v, y: sy(v) };
    });
});

const bandRect = computed(() => {
    if (!props.band) return null;
    const y1 = sy(props.band.to);
    const y2 = sy(props.band.from);
    return { y: Math.min(y1, y2), height: Math.abs(y2 - y1) };
});

// --- hover layer ---------------------------------------------------------
const hover = ref(null);

function onMove(e) {
    if (clean.value.length === 0) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * W;
    let best = 0;
    let bestD = Infinity;
    clean.value.forEach((_, i) => {
        const dd = Math.abs(sx(i) - x);
        if (dd < bestD) { bestD = dd; best = i; }
    });
    hover.value = best;
}

const hoverPoint = computed(() =>
    hover.value === null ? null : clean.value[hover.value]
);

const delta = computed(() => {
    // No verdict where the direction has no clinical meaning.
    if (!props.better || clean.value.length < 2) return null;
    const first = clean.value[0].y;
    const last = clean.value[clean.value.length - 1].y;
    if (first === 0) return null;
    const pct = ((last - first) / Math.abs(first)) * 100;
    return { pct, improving: props.better === 'lower' ? pct < 0 : pct > 0 };
});

const fmt = (v) => `${n(Number(v.toFixed(2)))}${props.unit ? ' ' + props.unit : ''}`;
</script>

<template>
    <div>
        <div v-if="delta" class="mb-2 flex items-center gap-2 text-xs">
            <span
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium"
                :class="delta.improving
                    ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                    : 'bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'"
            >
                <UIcon :name="delta.pct < 0 ? 'i-lucide-trending-down' : 'i-lucide-trending-up'" class="size-3" />
                {{ delta.pct > 0 ? '+' : '' }}{{ delta.pct.toFixed(0) }}%
            </span>
            <span class="text-slate-600 dark:text-slate-400">{{ label }}</span>
        </div>

        <svg
            v-if="clean.length"
            :viewBox="`0 0 ${W} ${height}`"
            class="w-full touch-none"
            role="img"
            :aria-label="label"
            @pointermove="onMove"
            @pointerleave="hover = null"
        >
            <!-- Healthy band sits behind everything: context, not a mark. -->
            <rect
                v-if="bandRect"
                :x="PAD.left" :y="bandRect.y" :width="plotW" :height="bandRect.height"
                :fill="stroke" opacity="0.07"
            />

            <g v-for="t in ticks" :key="t.v">
                <line :x1="PAD.left" :x2="W - PAD.right" :y1="t.y" :y2="t.y"
                      :stroke="axis.grid" stroke-width="1" />
                <text :x="PAD.left - 8" :y="t.y + 4" text-anchor="end"
                      :fill="axis.text" font-size="11">{{ n(Number(t.v.toFixed(1))) }}</text>
            </g>

            <path v-if="areaPath" :d="areaPath" :fill="stroke" opacity="0.10" />
            <path :d="linePath" fill="none" :stroke="stroke" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round" />

            <!-- Markers only when the series is short enough for them to help. -->
            <circle
                v-for="(p, i) in (clean.length <= 20 ? clean : [])"
                :key="i" :cx="sx(i)" :cy="sy(p.y)" r="4"
                :fill="stroke" :stroke="axis.surface" stroke-width="2"
            />

            <g v-if="hoverPoint !== null">
                <line :x1="sx(hover)" :x2="sx(hover)" :y1="PAD.top" :y2="PAD.top + plotH"
                      :stroke="axis.text" stroke-width="1" stroke-dasharray="3 3" />
                <circle :cx="sx(hover)" :cy="sy(hoverPoint.y)" r="6"
                        :fill="stroke" :stroke="axis.surface" stroke-width="2" />
            </g>

            <text :x="PAD.left" :y="height - 8" :fill="axis.text" font-size="11">
                {{ d(clean[0].x, 'date') }}
            </text>
            <text :x="W - PAD.right" :y="height - 8" text-anchor="end" :fill="axis.text" font-size="11">
                {{ d(clean[clean.length - 1].x, 'date') }}
            </text>
        </svg>

        <p v-else class="py-8 text-center text-sm text-slate-600 dark:text-slate-400">—</p>

        <!-- Tooltip as text, so the value is readable and announced rather than
             depending on a hover that a keyboard or screen reader cannot trigger. -->
        <p v-if="hoverPoint" class="mt-2 text-sm tabular-nums text-slate-900 dark:text-white">
            {{ d(hoverPoint.x, 'date') }} · <strong>{{ fmt(hoverPoint.y) }}</strong>
        </p>
    </div>
</template>
