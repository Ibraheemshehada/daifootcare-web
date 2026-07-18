<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { motion } from 'motion-v';
import { SUPPORTED_LOCALES, applyLocale } from '@/i18n';

const { t, locale } = useI18n();

const scrolled = ref(false);
const onScroll = () => (scrolled.value = window.scrollY > 8);
onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }));
onUnmounted(() => window.removeEventListener('scroll', onScroll));

/**
 * Shared entrance animation. Kept as one object so every section rises the same
 * distance at the same speed — a landing page where each block animates slightly
 * differently reads as unfinished rather than lively.
 */
const rise = (delay = 0) => ({
    initial: { opacity: 0, y: 24 },
    whileInView: { opacity: 1, y: 0 },
    inViewOptions: { once: true, margin: '-80px' },
    transition: { duration: 0.5, delay, ease: [0.22, 1, 0.36, 1] },
});

const steps = [
    { icon: 'i-lucide-camera', key: 'capture' },
    { icon: 'i-lucide-ruler', key: 'measure' },
    { icon: 'i-lucide-brain-circuit', key: 'analyse' },
    { icon: 'i-lucide-line-chart', key: 'track' },
];

const features = [
    { icon: 'i-lucide-scan-line', key: 'segmentation', accent: 'cyan' },
    { icon: 'i-lucide-layers', key: 'tissue', accent: 'violet' },
    { icon: 'i-lucide-shield-alert', key: 'risk', accent: 'rose' },
    { icon: 'i-lucide-droplet', key: 'glucose', accent: 'amber' },
    { icon: 'i-lucide-pill', key: 'medication', accent: 'emerald' },
    { icon: 'i-lucide-accessibility', key: 'accessibility', accent: 'cyan' },
];

const accentClasses = {
    cyan: 'bg-cyan-50 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-200',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
    rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
};
</script>

<template>
    <div class="min-h-screen bg-white dark:bg-slate-950">
        <!-- ── Nav ─────────────────────────────────────────────── -->
        <header
            class="fixed inset-x-0 top-0 z-50 transition-colors duration-300"
            :class="
                scrolled
                    ? 'border-b border-slate-200 bg-white/85 backdrop-blur-md dark:border-slate-800 dark:bg-slate-950/85'
                    : 'border-b border-transparent'
            "
        >
            <nav class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-5">
                <a href="#top" class="flex items-center gap-2.5">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-cyan-700 text-white">
                        <UIcon name="i-lucide-footprints" class="size-5" />
                    </span>
                    <span class="text-base font-semibold tracking-tight text-slate-900 dark:text-white">
                        {{ t('app.name') }}
                    </span>
                </a>

                <div class="ms-auto flex items-center gap-1">
                    <button
                        v-for="l in SUPPORTED_LOCALES"
                        :key="l.code"
                        type="button"
                        class="rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            locale === l.code
                                ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'
                        "
                        :aria-pressed="locale === l.code"
                        @click="applyLocale(l.code)"
                    >
                        {{ l.name }}
                    </button>

                    <UButton
                        :to="{ name: 'login' }"
                        size="sm"
                        class="ms-2"
                        :label="t('landing.nav_signin')"
                    />
                </div>
            </nav>
        </header>

        <!-- ── Hero ────────────────────────────────────────────── -->
        <section id="top" class="relative overflow-hidden px-5 pt-32 pb-20 sm:pt-40 sm:pb-28">
            <!-- Decorative only: hidden from assistive tech. -->
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
                <div
                    class="absolute start-1/2 top-[-12rem] size-[36rem] -translate-x-1/2 rounded-full bg-cyan-200/40 blur-3xl dark:bg-cyan-900/20"
                />
            </div>

            <div class="mx-auto max-w-3xl text-center">
                <motion.div v-bind="rise(0)">
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300"
                    >
                        <UIcon name="i-lucide-cpu" class="size-3.5 text-cyan-700 dark:text-cyan-400" />
                        {{ t('landing.badge') }}
                    </span>
                </motion.div>

                <motion.h1
                    v-bind="rise(0.08)"
                    class="mt-6 text-balance text-4xl font-semibold leading-[1.1] tracking-tight text-slate-900 sm:text-5xl md:text-6xl dark:text-white"
                >
                    {{ t('landing.hero_title') }}
                </motion.h1>

                <motion.p
                    v-bind="rise(0.16)"
                    class="mx-auto mt-6 max-w-2xl text-pretty text-lg leading-relaxed text-slate-700 dark:text-slate-300"
                >
                    {{ t('landing.hero_body') }}
                </motion.p>

                <motion.div
                    v-bind="rise(0.24)"
                    class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row"
                >
                    <UButton size="xl" :to="{ name: 'login' }" :label="t('landing.cta_primary')" />
                    <UButton
                        size="xl"
                        color="neutral"
                        variant="outline"
                        to="#how"
                        :label="t('landing.cta_secondary')"
                    />
                </motion.div>

                <motion.p v-bind="rise(0.32)" class="mt-6 text-sm text-slate-600 dark:text-slate-400">
                    {{ t('landing.hero_note') }}
                </motion.p>
            </div>
        </section>

        <!-- ── Modes ───────────────────────────────────────────── -->
        <section class="px-5 py-20 sm:py-24">
            <div class="mx-auto max-w-5xl">
                <motion.div v-bind="rise(0)" class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl dark:text-white">
                        {{ t('landing.modes_title') }}
                    </h2>
                    <p class="mt-4 text-pretty text-slate-700 dark:text-slate-300">
                        {{ t('landing.modes_body') }}
                    </p>
                </motion.div>

                <div class="mt-12 grid gap-5 md:grid-cols-2">
                    <motion.div
                        v-bind="rise(0.06)"
                        class="rounded-2xl border border-slate-200 bg-white p-7 dark:border-slate-800 dark:bg-slate-900"
                    >
                        <span class="flex size-11 items-center justify-center rounded-xl bg-cyan-50 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-200">
                            <UIcon name="i-lucide-cloud" class="size-5" />
                        </span>
                        <h3 class="mt-5 text-lg font-semibold text-slate-900 dark:text-white">
                            {{ t('landing.mode_online_title') }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                            {{ t('landing.mode_online_body') }}
                        </p>
                    </motion.div>

                    <motion.div
                        v-bind="rise(0.12)"
                        class="rounded-2xl border border-slate-200 bg-white p-7 dark:border-slate-800 dark:bg-slate-900"
                    >
                        <span class="flex size-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                            <UIcon name="i-lucide-cloud-off" class="size-5" />
                        </span>
                        <h3 class="mt-5 text-lg font-semibold text-slate-900 dark:text-white">
                            {{ t('landing.mode_offline_title') }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                            {{ t('landing.mode_offline_body') }}
                        </p>
                    </motion.div>
                </div>
            </div>
        </section>

        <!-- ── How it works ────────────────────────────────────── -->
        <section id="how" class="scroll-mt-16 bg-slate-50 px-5 py-20 sm:py-24 dark:bg-slate-900/40">
            <div class="mx-auto max-w-5xl">
                <motion.h2
                    v-bind="rise(0)"
                    class="text-center text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl dark:text-white"
                >
                    {{ t('landing.how_title') }}
                </motion.h2>

                <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <motion.li
                        v-for="(step, i) in steps"
                        :key="step.key"
                        v-bind="rise(i * 0.08)"
                        class="relative rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                    >
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <UIcon :name="step.icon" class="size-5" />
                        </span>
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-cyan-700 dark:text-cyan-300">
                            {{ t('landing.step') }} {{ i + 1 }}
                        </p>
                        <h3 class="mt-1 font-semibold text-slate-900 dark:text-white">
                            {{ t(`landing.step_${step.key}_title`) }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                            {{ t(`landing.step_${step.key}_body`) }}
                        </p>
                    </motion.li>
                </ol>
            </div>
        </section>

        <!-- ── Features ────────────────────────────────────────── -->
        <section class="px-5 py-20 sm:py-24">
            <div class="mx-auto max-w-5xl">
                <motion.div v-bind="rise(0)" class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl dark:text-white">
                        {{ t('landing.features_title') }}
                    </h2>
                    <p class="mt-4 text-pretty text-slate-700 dark:text-slate-300">
                        {{ t('landing.features_body') }}
                    </p>
                </motion.div>

                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <motion.div
                        v-for="(f, i) in features"
                        :key="f.key"
                        v-bind="rise((i % 3) * 0.06)"
                        class="rounded-2xl border border-slate-200 bg-white p-6 transition-shadow hover:shadow-sm dark:border-slate-800 dark:bg-slate-900"
                    >
                        <span
                            class="flex size-10 items-center justify-center rounded-xl"
                            :class="accentClasses[f.accent] ?? accentClasses.cyan"
                        >
                            <UIcon :name="f.icon" class="size-5" />
                        </span>
                        <h3 class="mt-4 font-semibold text-slate-900 dark:text-white">
                            {{ t(`landing.feature_${f.key}_title`) }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                            {{ t(`landing.feature_${f.key}_body`) }}
                        </p>
                    </motion.div>
                </div>
            </div>
        </section>

        <!-- ── Data & consent ──────────────────────────────────── -->
        <section class="px-5 pb-20 sm:pb-24">
            <motion.div
                v-bind="rise(0)"
                class="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-slate-50 p-8 sm:p-10 dark:border-slate-800 dark:bg-slate-900/60"
            >
                <span class="flex size-11 items-center justify-center rounded-xl bg-white text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <UIcon name="i-lucide-lock" class="size-5" />
                </span>
                <h2 class="mt-5 text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">
                    {{ t('landing.privacy_title') }}
                </h2>
                <!--
                    This section states plainly that records are linked to the patient's
                    account and reviewed by the study team. It deliberately avoids
                    "anonymous" and "private" — the app's own consent declaration was
                    rewritten for exactly that reason, and marketing copy that contradicts
                    the consent text would undo that work.
                -->
                <p class="mt-3 max-w-2xl text-pretty leading-relaxed text-slate-700 dark:text-slate-300">
                    {{ t('landing.privacy_body') }}
                </p>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="k in ['encrypted', 'account', 'study', 'withdraw']"
                        :key="k"
                        class="flex items-start gap-2.5 text-sm text-slate-700 dark:text-slate-300"
                    >
                        <UIcon name="i-lucide-check" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span>{{ t(`landing.privacy_${k}`) }}</span>
                    </li>
                </ul>
            </motion.div>
        </section>

        <!-- ── Footer ──────────────────────────────────────────── -->
        <footer class="border-t border-slate-200 px-5 py-10 dark:border-slate-800">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 text-sm text-slate-500 sm:flex-row dark:text-slate-500">
                <p>© {{ new Date().getFullYear() }} {{ t('app.name') }}</p>
                <p class="text-center sm:text-end">{{ t('landing.footer_disclaimer') }}</p>
            </div>
        </footer>
    </div>
</template>
