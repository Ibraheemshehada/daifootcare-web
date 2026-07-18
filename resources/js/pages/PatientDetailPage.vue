<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import LineChart from '@/components/charts/LineChart.vue';
import Meter from '@/components/charts/Meter.vue';

const { t, d } = useI18n();
const route = useRoute();

const { data, loading, error, load } = useApiResource(`/patients/${route.params.id}/record`);

const record = computed(() => data.value ?? {});
const summary = computed(() => record.value.summary ?? {});

const pct = (v) => (v === null || v === undefined ? null : `${v}%`);

const cards = computed(() => [
    { label: t('patient.scans'), value: summary.value.scans_total, icon: 'i-lucide-scan-line', accent: 'cyan' },
    { label: t('patient.glucose_avg'), value: summary.value.glucose_avg_7d, icon: 'i-lucide-droplet', accent: 'amber' },
    { label: t('patient.sus_latest'), value: summary.value.sus_latest, icon: 'i-lucide-clipboard-check', accent: 'slate' },
]);

/** Newest-first from the API; a chart reads left-to-right oldest-first. */
const glucoseSeries = computed(() =>
    [...(record.value.glucose ?? [])]
        .reverse()
        .map((g) => ({ x: g.measured_at, y: g.value_mgdl }))
);
/**
 * Wound area trend.
 *
 * Scans with no measurement are excluded — including the 0x0 case, which the
 * model returns when it finds **no wound in the photo**, not when the wound has
 * closed. Plotting those as a real zero would draw a line to the floor and read
 * as "fully healed", which is the opposite of what a retake-needed scan means.
 */
const areaSeries = computed(() =>
    [...(record.value.wound_scans ?? [])]
        .reverse()
        .filter((s) => Number.isFinite(s.area_cm2) && s.area_cm2 > 0)
        .map((s) => ({ x: s.captured_at, y: s.area_cm2 }))
);

const riskColor = (badge) =>
    ({ infection: 'error', high: 'error', ischaemia: 'warning', normal: 'success' })[badge] ?? 'neutral';

const upcoming = computed(() =>
    (record.value.appointments ?? []).filter((a) => new Date(a.scheduled_at) >= new Date())
);
</script>

<template>
    <div>
        <PageHeader
            :title="record.patient?.user?.name ?? t('patient.title')"
            :subtitle="record.patient?.user?.email"
        />

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

        <!-- Summary -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <StatCard v-for="(c, i) in cards" :key="c.label" v-bind="c" :loading="loading" :delay="i * 0.04" />
        </div>

        <TableSkeleton v-if="loading" class="mt-8" :columns="4" />

        <template v-else>
            <!-- Adherence: a ratio against a target is a meter, not a chart. -->
            <div class="mt-8 grid gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
                <Meter
                    :value="summary.self_care_adherence_30d"
                    :label="t('patient.selfcare_adherence')"
                    :threshold="70"
                    :threshold-label="t('patient.below_target')"
                />
                <Meter
                    :value="summary.medication_adherence_30d"
                    :label="t('patient.medication_adherence')"
                    :threshold="80"
                    :threshold-label="t('patient.below_target')"
                />
            </div>

            <!-- Trends -->
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.wound_area_trend') }}</h2>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('patient.wound_area_hint') }}</p>
                    <LineChart
                        class="mt-3"
                        :points="areaSeries"
                        unit="cm²"
                        better="lower"
                        :label="t('patient.wound_area_trend')"
                    />
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.glucose_trend') }}</h2>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('patient.glucose_hint') }}</p>
                    <!-- The shaded band is the common 70–130 mg/dL target range:
                         a reading only means something relative to it. -->
                    <LineChart
                        class="mt-3"
                        :points="glucoseSeries"
                        unit="mg/dL"
                        :band="{ from: 70, to: 130 }"
                        :label="t('patient.glucose_trend')"
                    />
                </div>
            </div>

            <!-- Scans -->
            <h2 class="mt-10 mb-3 text-lg font-semibold text-slate-900 dark:text-white">{{ t('patient.recent_scans') }}</h2>
            <div
                v-if="record.wound_scans?.length"
                class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
            >
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="border-b border-slate-200 dark:border-slate-800">
                        <tr class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">
                            <th class="px-4 py-3 text-start font-medium">{{ t('scans.captured') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ t('scans.size') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ t('scans.area') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ t('scans.risk') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="s in record.wound_scans.slice(0, 8)" :key="s.id">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                                {{ d(new Date(s.captured_at), 'short') }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                {{ (!s.length_cm && !s.width_cm) ? t('scans.no_wound')
                                    : `${s.length_cm?.toFixed(1)} × ${s.width_cm?.toFixed(1)} cm` }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                {{ s.area_cm2 ? `${s.area_cm2.toFixed(2)} cm²` : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <UBadge :color="riskColor(s.risk_badge)" variant="subtle"
                                    :label="t(`risk.${s.risk_badge ?? 'unknown'}`)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('scans.empty') }}</p>

            <!-- Appointments + consent -->
            <div class="mt-10 grid gap-5 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.upcoming') }}</h2>
                    <ul v-if="upcoming.length" class="space-y-3">
                        <li v-for="a in upcoming.slice(0, 5)" :key="a.id" class="flex items-start gap-3">
                            <UIcon name="i-lucide-calendar" class="mt-0.5 size-4 shrink-0 text-slate-500" />
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-900 dark:text-white">{{ a.title }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">
                                    {{ d(new Date(a.scheduled_at), 'short') }}<span v-if="a.location"> · {{ a.location }}</span>
                                </p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('patient.no_upcoming') }}</p>
                </div>

                <!--
                    Consent history is shown on the clinical record on purpose: it is
                    the evidence of what this participant agreed to, and a clinician
                    looking at synced data should be able to see the basis for it.
                -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.consent') }}</h2>
                    <ul v-if="record.consents?.length" class="space-y-3">
                        <li v-for="c in record.consents" :key="c.id" class="flex items-center gap-3">
                            <UBadge color="primary" variant="subtle" :label="`v${c.version}`" />
                            <span class="text-sm text-slate-600 dark:text-slate-400">
                                {{ d(new Date(c.accepted_at), 'date') }}
                                <span v-if="c.locale"> · {{ c.locale.toUpperCase() }}</span>
                                <span v-if="c.covers_prior"> · {{ t('patient.covers_prior') }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('patient.no_consent') }}</p>
                </div>
            </div>
        </template>
    </div>
</template>
