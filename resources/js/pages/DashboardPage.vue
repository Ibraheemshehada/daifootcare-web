<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import LineChart from '@/components/charts/LineChart.vue';
import BandBarChart from '@/components/charts/BandBarChart.vue';

const { t } = useI18n();
const { data, loading, error, load } = useApiResource('/dashboard/stats');

// Range selector — the one genuinely useful control here. A fixed window hides
// whether a quiet week is normal for this cohort or new.
const rangeDays = ref(30);
const trends = useApiResource('/dashboard/trends', { params: { days: 30 } });
watch(rangeDays, (d) => trends.load({ days: d }));

const series = computed(() => trends.data.value?.series ?? []);

const scanSeries = computed(() => series.value.map((r) => ({ x: r.day, y: r.scans })));
const activeSeries = computed(() => series.value.map((r) => ({ x: r.day, y: r.active_participants })));
const syncFailSeries = computed(() => series.value.map((r) => ({ x: r.day, y: r.sync_failed })));

/** Ordered worst-first, so the bar chart reads as a severity ranking. */
const riskBands = computed(() => {
    const r = trends.data.value?.risk_distribution ?? {};
    return [
        { key: 'high', label: t('risk.high'), value: r.high ?? 0 },
        { key: 'infection', label: t('risk.infection'), value: r.infection ?? 0 },
        { key: 'ischaemia', label: t('risk.ischaemia'), value: r.ischaemia ?? 0 },
        { key: 'normal', label: t('risk.normal'), value: r.normal ?? 0 },
    ];
});

const ranges = [7, 30, 90];

const stats = computed(() => data.value ?? {});

const cards = computed(() => [
    { label: t('dashboard.patients'), value: stats.value.patients, icon: 'i-lucide-users', accent: 'cyan' },
    { label: t('dashboard.guests'), value: stats.value.participants?.guests, icon: 'i-lucide-user-round-search', accent: 'slate' },
    { label: t('dashboard.devices'), value: stats.value.devices?.total, icon: 'i-lucide-smartphone', accent: 'slate' },
    { label: t('dashboard.active_devices'), value: stats.value.devices?.active_7d, icon: 'i-lucide-wifi', accent: 'emerald' },
    { label: t('dashboard.scans_total'), value: stats.value.scans?.total, icon: 'i-lucide-scan-line', accent: 'cyan' },
    { label: t('dashboard.scans_7d'), value: stats.value.scans?.last_7d, icon: 'i-lucide-calendar-days', accent: 'slate' },
    { label: t('dashboard.scans_30d'), value: stats.value.scans?.last_30d, icon: 'i-lucide-calendar-range', accent: 'slate' },
    { label: t('dashboard.infection_flagged'), value: stats.value.scans?.infection_flagged, icon: 'i-lucide-alert-triangle', accent: 'rose' },
    { label: t('dashboard.ischaemia_flagged'), value: stats.value.scans?.ischaemia_flagged, icon: 'i-lucide-heart-pulse', accent: 'amber' },
]);

const modeCards = computed(() => [
    { label: t('dashboard.mode_online'), value: stats.value.devices?.online_mode, icon: 'i-lucide-cloud', accent: 'cyan' },
    { label: t('dashboard.mode_offline'), value: stats.value.devices?.offline_mode, icon: 'i-lucide-cloud-off', accent: 'slate' },
    { label: t('dashboard.models_downloaded'), value: stats.value.devices?.models_downloaded, icon: 'i-lucide-hard-drive-download', accent: 'emerald' },
    { label: t('dashboard.sync_batches'), value: stats.value.sync?.batches_24h, icon: 'i-lucide-refresh-cw', accent: 'slate' },
    { label: t('dashboard.sync_failed'), value: stats.value.sync?.failed_24h, icon: 'i-lucide-x-circle', accent: 'rose' },
]);
</script>

<template>
    <div>
        <PageHeader :title="t('dashboard.title')" />

        <UAlert
            v-if="error"
            color="error"
            variant="soft"
            icon="i-lucide-alert-circle"
            :description="t('common.error')"
            class="mb-6"
        >
            <template #actions>
                <UButton color="error" variant="outline" size="xs" :label="t('common.retry')" @click="load()" />
            </template>
        </UAlert>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                v-for="(card, i) in cards"
                :key="card.label"
                v-bind="card"
                :loading="loading"
                :delay="i * 0.04"
            />
        </div>

        <!-- ── Trends ────────────────────────────────────────── -->
        <div class="mt-10 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                {{ t('dashboard.trends') }}
            </h2>
            <div class="flex gap-1" role="group" :aria-label="t('dashboard.range')">
                <button
                    v-for="r in ranges" :key="r" type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="rangeDays === r
                        ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                    :aria-pressed="rangeDays === r"
                    @click="rangeDays = r"
                >
                    {{ t('dashboard.last_n_days', { n: r }) }}
                </button>
            </div>
        </div>

        <div class="mt-4 grid gap-5 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('dashboard.scans_over_time') }}</h3>
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('dashboard.scans_over_time_hint') }}</p>
                <LineChart class="mt-3" :points="scanSeries" :height="180" :label="t('dashboard.scans_over_time')" />
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('dashboard.active_over_time') }}</h3>
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('dashboard.active_over_time_hint') }}</p>
                <LineChart class="mt-3" :points="activeSeries" :height="180" :label="t('dashboard.active_over_time')" />
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('dashboard.risk_distribution') }}</h3>
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('dashboard.risk_distribution_hint') }}</p>
                <BandBarChart class="mt-4" :bands="riskBands" />
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('dashboard.sync_failures') }}</h3>
                <!-- Plotted on its own rather than beside successes: two series of
                     wildly different scale on one axis hides the small one, and the
                     small one is the problem. -->
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('dashboard.sync_failures_hint') }}</p>
                <LineChart class="mt-3" :points="syncFailSeries" :height="180" :label="t('dashboard.sync_failures')" />
            </div>
        </div>

        <h2 class="mt-10 mb-4 text-lg font-semibold text-slate-900 dark:text-white">
            {{ t('dashboard.mode_split') }}
        </h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <StatCard
                v-for="(card, i) in modeCards"
                :key="card.label"
                v-bind="card"
                :loading="loading"
                :delay="i * 0.04"
            />
        </div>
    </div>
</template>
