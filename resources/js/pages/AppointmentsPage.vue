<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';

const { t, d } = useI18n();
const scope = ref('upcoming');
const { data, loading, error, load } = useApiResource('/appointments', { params: { scope: 'upcoming' } });
watch(scope, (s) => load({ scope: s }));

const rows = computed(() => data.value?.data ?? []);
const columns = computed(() => [
    { key: 'scheduled_at', label: t('appointments.when') },
    { key: 'patient', label: t('scans.patient') },
    { key: 'title', label: t('appointments.what') },
    { key: 'location', label: t('appointments.where') },
]);
const scopes = ['upcoming', 'past', 'all'];
</script>

<template>
    <div>
        <PageHeader :title="t('appointments.title')" :subtitle="t('appointments.subtitle')" />

        <div class="mb-4 flex gap-1" role="group" :aria-label="t('appointments.filter')">
            <button v-for="s in scopes" :key="s" type="button"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                :class="scope === s
                    ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                    : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                :aria-pressed="scope === s" @click="scope = s">
                {{ t(`appointments.scope_${s}`) }}
            </button>
        </div>

        <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error"
                   :empty-text="t('appointments.empty')" empty-icon="i-lucide-calendar"
                   @retry="load({ scope })">
            <template #cell-scheduled_at="{ row }">
                <span class="whitespace-nowrap text-slate-600 dark:text-slate-300">
                    {{ d(new Date(row.scheduled_at), 'short') }}
                </span>
            </template>
            <template #cell-patient="{ row }">
                <RouterLink v-if="row.patient" :to="{ name: 'patient-detail', params: { id: row.patient.id } }"
                    class="font-medium text-cyan-800 hover:underline dark:text-cyan-300">
                    {{ row.patient?.user?.name ?? '—' }}
                </RouterLink>
                <span v-else>—</span>
            </template>
            <template #cell-title="{ row }">
                <span class="text-slate-900 dark:text-white">{{ row.title }}</span>
            </template>
            <template #cell-location="{ row }">
                <span class="text-slate-600 dark:text-slate-300">{{ row.location ?? '—' }}</span>
            </template>
        </DataTable>

        <Pagination :meta="data" :loading="loading" @change="(p) => load({ page: p })" />
    </div>
</template>
