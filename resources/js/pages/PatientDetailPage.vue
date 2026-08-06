<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import DeviceCard from '@/components/DeviceCard.vue';
import LineChart from '@/components/charts/LineChart.vue';
import Meter from '@/components/charts/Meter.vue';

/**
 * One patient's complete record on one page.
 *
 * Everything the app collects for this person, in the order a clinician reads
 * it: who and how they are doing, then the wound itself, then the habits that
 * drive healing, then the devices the data came from, then the study
 * instruments and the consent it all rests on.
 */
const { t, d, n } = useI18n();
const route = useRoute();

const { data, loading, error, load } = useApiResource(`/patients/${route.params.id}/record`);

const record = computed(() => data.value ?? {});
const summary = computed(() => record.value.summary ?? {});
const devices = computed(() => record.value.devices ?? []);
const meds = computed(() => record.value.medications ?? []);

const cards = computed(() => [
    { label: t('patient.scans'), value: summary.value.scans_total, icon: 'i-lucide-scan-line', accent: 'cyan' },
    { label: t('patient.glucose_avg'), value: summary.value.glucose_avg_7d, icon: 'i-lucide-droplet', accent: 'amber' },
    { label: t('patient.sus_latest'), value: summary.value.sus_latest, icon: 'i-lucide-clipboard-check', accent: 'slate' },
    { label: t('patient.devices'), value: devices.value.length, icon: 'i-lucide-smartphone', accent: 'slate' },
]);

/** Newest-first from the API; a chart reads left-to-right oldest-first. */
/**
 * The scan opened in the viewer, or null when it is closed.
 *
 * Holds the scan object rather than an id so the dialog can render immediately
 * from data the table already has — the photograph is the only thing that needs
 * fetching, and it streams in behind its own loading state.
 */
const openedScan = ref(null);

/**
 * Wound photographs are on the server's private disk, so they are read through
 * the authorising endpoint rather than a public path — see WoundScan::$hidden.
 */
const imageUrl = (scan) => `/api/v1/wound-scans/${scan.local_uuid}/image`;

function openScan(scan) {
    openedScan.value = scan;
}

const glucoseSeries = computed(() =>
    [...(record.value.glucose ?? [])].reverse().map((g) => ({ x: g.measured_at, y: g.value_mgdl }))
);

/**
 * Wound area trend.
 *
 * Scans with no measurement are excluded — including the 0×0 case, which the
 * model returns when it finds **no wound in the photo**, not when the wound has
 * closed. Plotting those as a real zero would read as "fully healed".
 */
const areaSeries = computed(() =>
    [...(record.value.wound_scans ?? [])]
        .reverse()
        .filter((s) => Number.isFinite(s.area_cm2) && s.area_cm2 > 0)
        .map((s) => ({ x: s.captured_at, y: s.area_cm2 }))
);

/** Higher is worse on these scales, so the chart is not given a good/bad verdict. */
const qolSeries = computed(() =>
    [...(record.value.qol ?? [])]
        .reverse()
        .map((q) => ({ x: q.recorded_at, y: (q.pain + q.mobility + q.emotional) / 3 }))
);

const riskColor = (badge) =>
    ({ infection: 'error', high: 'error', ischaemia: 'warning', normal: 'success' })[badge] ?? 'neutral';

const upcoming = computed(() =>
    (record.value.appointments ?? []).filter((a) => new Date(a.scheduled_at) >= new Date())
);

const isGuest = computed(() => record.value.patient?.user?.is_guest);
const medBand = (v) => (v === null ? 'neutral' : v >= 80 ? 'success' : v >= 50 ? 'warning' : 'error');
</script>

<template>
    <div>
        <PageHeader
            :title="record.patient?.user?.name ?? t('patient.title')"
            :subtitle="record.patient?.user?.email ?? t('patient.no_email')"
        />

        <!-- A record with nobody identified behind it changes how everything
             below should be read, so it is said at the top. -->
        <UAlert
            v-if="isGuest"
            color="neutral" variant="subtle" icon="i-lucide-user-round-search"
            :description="t('patient.guest_notice')" class="mb-6"
        />

        <UAlert
            v-if="error" color="error" variant="soft" icon="i-lucide-alert-circle"
            :description="t('common.error')" class="mb-6"
        >
            <template #actions>
                <UButton color="error" variant="outline" size="xs" :label="t('common.retry')" @click="load()" />
            </template>
        </UAlert>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard v-for="(c, i) in cards" :key="c.label" v-bind="c" :loading="loading" :delay="i * 0.04" />
        </div>

        <TableSkeleton v-if="loading" class="mt-8" :columns="4" />

        <template v-else>
            <!-- ── Adherence ─────────────────────────────────────── -->
            <div class="mt-8 grid gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
                <Meter
                    :value="summary.self_care_adherence_30d" :label="t('patient.selfcare_adherence')"
                    :threshold="70" :threshold-label="t('patient.below_target')"
                />
                <Meter
                    :value="summary.medication_adherence_30d" :label="t('patient.medication_adherence')"
                    :threshold="80" :threshold-label="t('patient.below_target')"
                />
            </div>

            <!-- ── Trends ────────────────────────────────────────── -->
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.wound_area_trend') }}</h2>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('patient.wound_area_hint') }}</p>
                    <LineChart class="mt-3" :points="areaSeries" unit="cm²" better="lower"
                               :label="t('patient.wound_area_trend')" />
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.glucose_trend') }}</h2>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('patient.glucose_hint') }}</p>
                    <!-- Shaded band is the common 70–130 mg/dL target range. -->
                    <LineChart class="mt-3" :points="glucoseSeries" unit="mg/dL" :band="{ from: 70, to: 130 }"
                               :label="t('patient.glucose_trend')" />
                </div>
            </div>

            <!-- ── Devices ───────────────────────────────────────── -->
            <section class="mt-10">
                <h2 class="mb-1 text-lg font-semibold text-slate-900 dark:text-white">{{ t('patient.devices_title') }}</h2>
                <p class="mb-4 text-sm text-slate-600 dark:text-slate-400">{{ t('patient.devices_hint') }}</p>

                <div v-if="devices.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <DeviceCard v-for="dev in devices" :key="dev.id" :device="dev" />
                </div>
                <p v-else class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-600 dark:border-slate-700 dark:text-slate-400">
                    {{ t('patient.no_devices') }}
                </p>
            </section>

            <!-- ── Scans ─────────────────────────────────────────── -->
            <section class="mt-10">
                <h2 class="mb-3 text-lg font-semibold text-slate-900 dark:text-white">{{ t('patient.recent_scans') }}</h2>
                <div v-if="record.wound_scans?.length"
                     class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="border-b border-slate-200 dark:border-slate-800">
                            <tr class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">
                                <th class="px-4 py-3 text-start font-medium">{{ t('scans.photo') }}</th>
                                <th class="px-4 py-3 text-start font-medium">{{ t('scans.captured') }}</th>
                                <th class="px-4 py-3 text-start font-medium">{{ t('scans.size') }}</th>
                                <th class="px-4 py-3 text-start font-medium">{{ t('scans.area') }}</th>
                                <th class="px-4 py-3 text-start font-medium">{{ t('scans.risk') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <!--
                                The whole row opens the scan, not just the
                                thumbnail: a clinician reading the numbers and
                                deciding they want to see the wound should not
                                have to go back and find a 48px target.
                            -->
                            <tr v-for="s in record.wound_scans.slice(0, 10)" :key="s.id"
                                class="cursor-pointer transition hover:bg-slate-50 dark:hover:bg-slate-800/60"
                                tabindex="0"
                                role="button"
                                :aria-label="t('scans.viewPhoto')"
                                @click="openScan(s)"
                                @keydown.enter.prevent="openScan(s)"
                                @keydown.space.prevent="openScan(s)">
                                <td class="px-4 py-3">
                                    <!-- Served through the API, never a public
                                         URL: the file is on the private disk
                                         because a wound image tied to a named
                                         patient must not be reachable by
                                         guessing a path. -->
                                    <img v-if="s.local_uuid && s.has_image"
                                         :src="imageUrl(s)"
                                         :alt="t('scans.photo')"
                                         loading="lazy"
                                         class="h-12 w-12 rounded-lg border border-slate-200 object-cover dark:border-slate-700" />
                                    <span v-else
                                          class="flex h-12 w-12 items-center justify-center rounded-lg border border-dashed border-slate-300 text-xs text-slate-400 dark:border-slate-700">
                                        —
                                    </span>
                                </td>
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
                                    <!-- The tissue the model actually found.
                                         Shown next to the risk badge because the
                                         two are read together. -->
                                    <span v-if="s.tissue_summary"
                                          class="ms-2 text-xs text-slate-500 dark:text-slate-400">
                                        {{ s.tissue_summary }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('scans.empty') }}</p>

                <!--
                    Scan viewer. The photograph and the numbers the model
                    produced from it belong on one screen: reading "0.9 cm" is a
                    different act when you can see the wound it came from.
                -->
                <UModal v-model:open="openedScan" :title="t('scans.viewPhoto')">
                    <template #content>
                        <div v-if="openedScan" class="p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">
                                        {{ d(new Date(openedScan.captured_at), 'long') }}
                                    </h3>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        {{ t(`scans.source_${openedScan.source ?? 'offline'}`) }}
                                    </p>
                                </div>
                                <UButton icon="i-lucide-x" variant="ghost" color="neutral"
                                         :aria-label="t('common.close')"
                                         @click="openedScan = null" />
                            </div>

                            <img v-if="openedScan.local_uuid && openedScan.has_image"
                                 :src="imageUrl(openedScan)"
                                 :alt="t('scans.photo')"
                                 class="mt-4 max-h-[55vh] w-full rounded-xl border border-slate-200 object-contain dark:border-slate-700" />
                            <p v-else
                               class="mt-4 rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">
                                {{ t('scans.no_photo') }}
                            </p>

                            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div>
                                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('scans.size') }}</dt>
                                    <dd class="text-sm tabular-nums text-slate-900 dark:text-white">
                                        {{ (!openedScan.length_cm && !openedScan.width_cm) ? t('scans.no_wound')
                                            : `${openedScan.length_cm?.toFixed(1)} × ${openedScan.width_cm?.toFixed(1)} cm` }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('scans.area') }}</dt>
                                    <dd class="text-sm tabular-nums text-slate-900 dark:text-white">
                                        {{ openedScan.area_cm2 ? `${openedScan.area_cm2.toFixed(2)} cm²` : '—' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('scans.risk') }}</dt>
                                    <dd class="mt-0.5">
                                        <UBadge :color="riskColor(openedScan.risk_badge)" variant="subtle"
                                                :label="t(`risk.${openedScan.risk_badge ?? 'unknown'}`)" />
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('scans.tissue') }}</dt>
                                    <dd class="text-sm text-slate-900 dark:text-white">
                                        {{ openedScan.tissue_summary || '—' }}
                                    </dd>
                                </div>
                            </dl>

                            <!-- Measurements are uncalibrated unless a scale
                                 reference was in frame, so the absolute figures
                                 above carry a caveat the trend does not. -->
                            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                                {{ t('scans.measure_caveat') }}
                            </p>
                        </div>
                    </template>
                </UModal>
            </section>

            <!-- ── Medications + well-being ──────────────────────── -->
            <div class="mt-10 grid gap-5 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">{{ t('medications.title') }}</h2>
                    <ul v-if="meds.length" class="space-y-3">
                        <li v-for="m in meds" :key="m.id" class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-900 dark:text-white">{{ m.name }}</p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">
                                    <span v-if="m.dosage">{{ m.dosage }} · </span>
                                    {{ t('medications.per_day', { n: m.times_per_day }) }}
                                </p>
                            </div>
                            <UBadge v-if="m.adherence_30d !== null" :color="medBand(m.adherence_30d)"
                                    variant="subtle" :label="`${m.adherence_30d}%`" />
                            <span v-else class="text-xs text-slate-500">{{ t('medications.no_logs') }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('medications.empty') }}</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.wellbeing') }}</h2>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('patient.wellbeing_hint') }}</p>
                    <!-- No good/bad verdict: this is a 0–10 burden scale, and a
                         direction badge would imply a clinical judgement. -->
                    <LineChart v-if="qolSeries.length" class="mt-3" :points="qolSeries" :height="150"
                               :label="t('patient.wellbeing')" />
                    <p v-else class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ t('patient.no_wellbeing') }}</p>
                </div>
            </div>

            <!-- ── Study instruments, appointments, consent ──────── -->
            <div class="mt-10 grid gap-5 lg:grid-cols-3">
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

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-1 text-sm font-semibold text-slate-900 dark:text-white">{{ t('patient.sus_history') }}</h2>
                    <p class="mb-3 text-xs text-slate-600 dark:text-slate-400">{{ t('study.benchmark_note') }}</p>
                    <ul v-if="record.sus?.length" class="space-y-2">
                        <li v-for="r in record.sus.slice(0, 5)" :key="r.id"
                            class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-slate-600 dark:text-slate-400">{{ d(new Date(r.recorded_at), 'date') }}</span>
                            <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ n(r.score) }}
                                <span v-if="r.consent_version === null" class="ms-1 text-xs font-normal text-amber-700 dark:text-amber-400">
                                    {{ t('patient.pre_consent') }}
                                </span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('patient.no_sus') }}</p>
                </div>

                <!-- Consent belongs on the clinical record: it is the evidence
                     for why any of this data is here at all. -->
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
