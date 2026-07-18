<script setup>
import { motion } from 'motion-v';

defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: null },
    icon: { type: String, default: 'i-lucide-activity' },
    accent: { type: String, default: 'sky' },
    loading: { type: Boolean, default: false },
    delay: { type: Number, default: 0 },
});

const accents = {
    cyan: 'bg-cyan-50 text-cyan-800 dark:bg-cyan-950 dark:text-cyan-200',
    emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};
</script>

<template>
    <motion.div
        :initial="{ opacity: 0, y: 12 }"
        :animate="{ opacity: 1, y: 0 }"
        :transition="{ duration: 0.3, delay }"
        class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <!--
                    Labels wrap rather than truncate. In a narrow tile "Failed or
                    partial, 24h" truncates to "Failed or p…", which in a clinical
                    dashboard is worse than a taller card.
                -->
                <p class="text-sm font-medium text-balance text-slate-600 dark:text-slate-400">
                    {{ label }}
                </p>

                <USkeleton v-if="loading" class="mt-2 h-8 w-20" />
                <p v-else class="mt-1 text-3xl font-semibold tabular-nums text-slate-900 dark:text-white">
                    {{ value ?? '—' }}
                </p>
            </div>

            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg" :class="accents[accent] ?? accents.slate">
                <UIcon :name="icon" class="size-5" />
            </div>
        </div>
    </motion.div>
</template>
