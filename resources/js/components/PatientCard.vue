<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Sparkline from '@/components/charts/Sparkline.vue';

/**
 * One participant, as a monitoring tile.
 *
 * Ordered so the clinical question comes first: does this person need attention,
 * and why. Counts and adherence are context for that answer, not the headline —
 * a card that leads with "7 scans" makes you read every tile to find the one
 * that matters.
 */
const props = defineProps({
    patient: { type: Object, required: true },
});

const { t, d } = useI18n();

const user = computed(() => props.patient.user ?? {});
const reasons = computed(() => props.patient.attention ?? []);

/** The worst reason drives the card's border and badge. */
const severity = computed(() => {
    if (reasons.value.some((r) => r.level === 'critical')) return 'critical';
    if (reasons.value.some((r) => r.level === 'warning')) return 'warning';
    if (reasons.value.length) return 'info';
    return 'ok';
});

const frame = {
    critical: 'border-red-300 dark:border-red-900',
    warning: 'border-amber-300 dark:border-amber-900',
    info: 'border-slate-200 dark:border-slate-800',
    ok: 'border-slate-200 dark:border-slate-800',
};

const badgeColor = { critical: 'error', warning: 'warning', info: 'neutral', ok: 'success' };

const lastScan = computed(() =>
    props.patient.last_scan_at ? d(new Date(props.patient.last_scan_at), 'date') : t('common.never')
);

const initial = computed(() => (user.value.name ?? '?').trim().charAt(0).toUpperCase() || '?');

/** Adherence bands. 80% is the conventional threshold for chronic medication. */
const band = (v) => (v === null || v === undefined ? 'neutral' : v >= 80 ? 'success' : v >= 50 ? 'warning' : 'error');
const barColor = (v) =>
    ({ success: 'bg-emerald-600', warning: 'bg-amber-600', error: 'bg-red-600', neutral: 'bg-slate-300 dark:bg-slate-700' })[band(v)];
</script>

<template>
    <RouterLink
        :to="{ name: 'patient-detail', params: { id: patient.id } }"
        class="flex flex-col rounded-xl border bg-white p-5 transition-shadow hover:shadow-md dark:bg-slate-900"
        :class="frame[severity]"
    >
        <!-- Identity -->
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
                {{ initial }}
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold text-slate-900 dark:text-white">
                    {{ user.name ?? '—' }}
                </p>
                <p class="truncate text-xs text-slate-600 dark:text-slate-400">
                    <template v-if="user.email">{{ user.email }}</template>
                    <code v-else-if="user.guest_device_uuid">{{ user.guest_device_uuid.slice(0, 8) }}…</code>
                    <template v-else>—</template>
                </p>
            </div>

            <UBadge v-if="user.is_guest" color="neutral" variant="subtle" size="xs" :label="t('patients.guest')" />
            <UBadge v-else-if="user.claimed_at" color="success" variant="subtle" size="xs" :label="t('patients.claimed')" />
        </div>

        <!-- Why this card is here. Reasons are words, never colour alone. -->
        <div v-if="reasons.length" class="mt-4 flex flex-wrap gap-1.5">
            <UBadge
                v-for="r in reasons" :key="r.key"
                :color="badgeColor[r.level]" variant="subtle" size="xs"
                :label="t(`patients.reason_${r.key}`)"
            />
        </div>
        <div v-else class="mt-4">
            <UBadge color="success" variant="subtle" size="xs"
                    icon="i-lucide-check" :label="t('patients.no_flags')" />
        </div>

        <!-- Wound trajectory: the shape matters more than the numbers here. -->
        <div class="mt-4">
            <div class="flex items-baseline justify-between">
                <span class="text-xs text-slate-600 dark:text-slate-400">{{ t('patients.wound_trend') }}</span>
                <span class="text-xs text-slate-500">{{ patient.wound_scans_count }} {{ t('patients.scans_short') }}</span>
            </div>
            <Sparkline
                v-if="(patient.area_series ?? []).length > 1"
                class="mt-1" :values="patient.area_series" :height="36"
                :stroke="severity === 'critical' ? '#b91c1c' : '#2a78d6'"
            />
            <p v-else class="mt-1 text-xs text-slate-500">{{ t('patients.not_enough_scans') }}</p>
        </div>

        <!-- Adherence -->
        <dl class="mt-4 grid grid-cols-2 gap-3">
            <div v-for="m in [
                { key: 'self_care', value: patient.self_care_adherence },
                { key: 'medication', value: patient.medication_adherence },
            ]" :key="m.key">
                <dt class="text-[0.65rem] uppercase tracking-wide text-slate-500">
                    {{ t(`patients.${m.key}_short`) }}
                </dt>
                <dd class="mt-1 flex items-center gap-2">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full" :class="barColor(m.value)"
                             :style="{ width: `${m.value ?? 0}%` }" />
                    </div>
                    <span class="w-9 text-end text-xs tabular-nums text-slate-700 dark:text-slate-300">
                        {{ m.value === null || m.value === undefined ? '—' : `${m.value}%` }}
                    </span>
                </dd>
            </div>
        </dl>

        <!-- Footer: recency and where the data comes from. -->
        <div class="mt-4 flex items-center gap-3 border-t border-slate-100 pt-3 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-400">
            <span class="flex items-center gap-1">
                <UIcon name="i-lucide-scan-line" class="size-3.5 shrink-0" />
                {{ lastScan }}
            </span>
            <span class="ms-auto flex items-center gap-1">
                <UIcon name="i-lucide-smartphone" class="size-3.5 shrink-0" />
                {{ patient.devices_count ?? 0 }}
            </span>
        </div>
    </RouterLink>
</template>
