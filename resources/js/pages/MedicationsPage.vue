<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';

const { t } = useI18n();
const { data, loading, error, load } = useApiResource('/medications');
const rows = computed(() => data.value?.data ?? []);

const columns = computed(() => [
    { key: 'name', label: t('medications.name') },
    { key: 'patient', label: t('scans.patient') },
    { key: 'dosage', label: t('medications.dosage') },
    { key: 'times_per_day', label: t('medications.frequency') },
    { key: 'adherence_30d', label: t('medications.adherence') },
    { key: 'is_active', label: t('medications.status') },
]);

/** 80% is the conventional adherence threshold for chronic medication. */
const band = (v) => (v === null ? 'neutral' : v >= 80 ? 'success' : v >= 50 ? 'warning' : 'error');
</script>

<template>
    <div>
        <PageHeader :title="t('medications.title')" :subtitle="t('medications.subtitle')" />
        <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error"
                   :empty-text="t('medications.empty')" empty-icon="i-lucide-pill" @retry="load()">
            <template #cell-name="{ row }">
                <span class="font-medium text-slate-900 dark:text-white">{{ row.name }}</span>
            </template>
            <template #cell-patient="{ row }">
                <RouterLink v-if="row.patient" :to="{ name: 'patient-detail', params: { id: row.patient.id } }"
                    class="text-cyan-800 hover:underline dark:text-cyan-300">
                    {{ row.patient?.user?.name ?? '—' }}
                </RouterLink>
                <span v-else>—</span>
            </template>
            <template #cell-dosage="{ row }">
                <span class="text-slate-600 dark:text-slate-300">{{ row.dosage ?? '—' }}</span>
            </template>
            <template #cell-times_per_day="{ row }">
                <span class="tabular-nums text-slate-600 dark:text-slate-300">
                    {{ t('medications.per_day', { n: row.times_per_day }) }}
                </span>
            </template>
            <template #cell-adherence_30d="{ row }">
                <!-- Colour is paired with the number, never carrying it alone. -->
                <UBadge v-if="row.adherence_30d !== null" :color="band(row.adherence_30d)"
                        variant="subtle" :label="`${row.adherence_30d}%`" />
                <span v-else class="text-slate-500">{{ t('medications.no_logs') }}</span>
            </template>
            <template #cell-is_active="{ row }">
                <UBadge :color="row.is_active ? 'success' : 'neutral'" variant="subtle"
                        :label="row.is_active ? t('medications.active') : t('medications.stopped')" />
            </template>
        </DataTable>

        <Pagination :meta="data" :loading="loading" @change="(p) => load({ page: p })" />
    </div>
</template>
