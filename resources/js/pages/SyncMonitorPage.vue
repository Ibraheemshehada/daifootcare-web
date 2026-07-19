<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';

const { t, d } = useI18n();
const failedOnly = ref(false);
const { data, loading, error, load } = useApiResource('/sync-monitor');
watch(failedOnly, (v) => load({ failed_only: v ? 1 : 0 }));

const summary = computed(() => data.value?.summary ?? {});
const rows = computed(() => data.value?.logs?.data ?? []);

const cards = computed(() => [
    { label: t('sync.batches_24h'), value: summary.value.batches_24h, icon: 'i-lucide-refresh-cw', accent: 'cyan' },
    { label: t('sync.records_24h'), value: summary.value.records_24h, icon: 'i-lucide-database', accent: 'emerald' },
    { label: t('sync.failed_24h'), value: summary.value.failed_24h, icon: 'i-lucide-x-circle', accent: 'rose' },
    { label: t('sync.stale_devices'), value: summary.value.stale_devices, icon: 'i-lucide-wifi-off', accent: 'amber' },
]);

const columns = computed(() => [
    { key: 'created_at', label: t('sync.when') },
    { key: 'device', label: t('devices.device') },
    { key: 'records_count', label: t('sync.records') },
    { key: 'status', label: t('sync.status') },
]);

const statusColor = { success: 'success', partial: 'warning', failed: 'error', pending: 'neutral' };
</script>

<template>
    <div>
        <PageHeader :title="t('sync.title')" :subtitle="t('sync.subtitle')" />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard v-for="(c, i) in cards" :key="c.label" v-bind="c" :loading="loading" :delay="i * 0.04" />
        </div>

        <label class="mt-6 mb-4 flex w-fit items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input v-model="failedOnly" type="checkbox" class="size-4 rounded border-slate-300" />
            {{ t('sync.failed_only') }}
        </label>

        <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error"
                   :empty-text="t('sync.empty')" empty-icon="i-lucide-refresh-cw"
                   @retry="load({ failed_only: failedOnly ? 1 : 0 })">
            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-slate-600 dark:text-slate-300">
                    {{ d(new Date(row.created_at), 'short') }}
                </span>
            </template>
            <template #cell-device="{ row }">
                <RouterLink v-if="row.device"
                    :to="{ name: 'device-detail', params: { uuid: row.device.device_uuid } }"
                    class="text-cyan-800 hover:underline dark:text-cyan-300">
                    <code class="text-xs">{{ row.device.device_uuid.slice(0, 8) }}…</code>
                </RouterLink>
                <span v-else class="text-slate-500">—</span>
            </template>
            <template #cell-records_count="{ row }">
                <span class="tabular-nums text-slate-600 dark:text-slate-300">
                    {{ row.synced_count }} / {{ row.records_count }}
                    <span v-if="row.failed_count" class="text-red-700 dark:text-red-400">
                        · {{ t('sync.n_failed', { n: row.failed_count }) }}
                    </span>
                </span>
            </template>
            <template #cell-status="{ row }">
                <UBadge :color="statusColor[row.status] ?? 'neutral'" variant="subtle"
                        :label="t(`sync.status_${row.status}`)" />
            </template>
        </DataTable>

        <Pagination :meta="data?.logs" :loading="loading" @change="(p) => load({ page: p })" />
    </div>
</template>
