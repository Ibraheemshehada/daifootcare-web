<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';

const { t, d } = useI18n();
const { data, loading, error, load } = useApiResource('/alerts');
const rows = computed(() => data.value?.data ?? []);

const columns = computed(() => [
    { key: 'captured_at', label: t('scans.captured') },
    { key: 'patient', label: t('scans.patient') },
    { key: 'size', label: t('scans.size') },
    { key: 'flags', label: t('alerts.flags') },
    { key: 'severity', label: t('alerts.severity') },
]);

/** Mirrors the app's own rule so both surfaces name the same risk identically. */
function severity(s) {
    if (s.infection_present && s.ischaemia_present) return { key: 'risk.high', color: 'error' };
    if (s.infection_present) return { key: 'risk.infection', color: 'error' };
    return { key: 'risk.ischaemia', color: 'warning' };
}
</script>

<template>
    <div>
        <PageHeader :title="t('alerts.title')" :subtitle="t('alerts.subtitle')" />
        <DataTable
            :columns="columns" :rows="rows" :loading="loading" :error="error"
            :empty-text="t('alerts.empty')" empty-icon="i-lucide-shield-check"
            @retry="load()"
        >
            <template #cell-captured_at="{ row }">
                <span class="whitespace-nowrap text-slate-600 dark:text-slate-300">
                    {{ d(new Date(row.captured_at), 'short') }}
                </span>
            </template>
            <template #cell-patient="{ row }">
                <RouterLink v-if="row.patient"
                    :to="{ name: 'patient-detail', params: { id: row.patient.id } }"
                    class="font-medium text-cyan-800 hover:underline dark:text-cyan-300">
                    {{ row.patient?.user?.name ?? '—' }}
                </RouterLink>
                <span v-else>—</span>
            </template>
            <template #cell-size="{ row }">
                <span class="tabular-nums text-slate-600 dark:text-slate-300">
                    {{ (!row.length_cm && !row.width_cm) ? t('scans.no_wound')
                        : `${row.length_cm?.toFixed(1)} × ${row.width_cm?.toFixed(1)} cm` }}
                </span>
            </template>
            <template #cell-flags="{ row }">
                <div class="flex flex-wrap gap-1.5">
                    <UBadge v-if="row.infection_present" color="error" variant="subtle" :label="t('risk.infection')" />
                    <UBadge v-if="row.ischaemia_present" color="warning" variant="subtle" :label="t('risk.ischaemia')" />
                </div>
            </template>
            <template #cell-severity="{ row }">
                <UBadge :color="severity(row).color" variant="solid" :label="t(severity(row).key)" />
            </template>
        </DataTable>

        <Pagination :meta="data" :loading="loading" @change="(p) => load({ page: p })" />
    </div>
</template>
