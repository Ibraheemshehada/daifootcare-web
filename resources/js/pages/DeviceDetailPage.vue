<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import DataTable from '@/components/DataTable.vue';

const { t, d } = useI18n();
const route = useRoute();
const { data, loading, error, load } = useApiResource(`/devices/${route.params.uuid}/detail`);

const device = computed(() => data.value?.device ?? {});
const totals = computed(() => data.value?.totals ?? {});
const logs = computed(() => data.value?.sync_logs ?? []);

const cards = computed(() => [
    { label: t('devices.scans_sent'), value: totals.value.scans, icon: 'i-lucide-scan-line', accent: 'cyan' },
    { label: t('sync.batches'), value: totals.value.batches, icon: 'i-lucide-refresh-cw', accent: 'slate' },
    { label: t('sync.failed_batches'), value: totals.value.failed_batches, icon: 'i-lucide-x-circle', accent: 'rose' },
]);

const columns = computed(() => [
    { key: 'created_at', label: t('sync.when') },
    { key: 'records_count', label: t('sync.records') },
    { key: 'status', label: t('sync.status') },
]);

const statusColor = { success: 'success', partial: 'warning', failed: 'error', pending: 'neutral' };

const fmt = (v) => (v ? d(new Date(v), 'short') : t('common.never'));
</script>

<template>
    <div>
        <PageHeader :title="t('devices.detail_title')" :subtitle="device.device_uuid" />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard v-for="(c, i) in cards" :key="c.label" v-bind="c" :loading="loading" :delay="i * 0.04" />
        </div>

        <dl class="mt-5 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4 dark:border-slate-800 dark:bg-slate-900">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ t('devices.owner') }}</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                    {{ device.user?.name ?? '—' }}
                    <UBadge v-if="device.user?.is_guest" color="neutral" variant="subtle" size="xs"
                            class="ms-1" :label="t('patients.guest')" />
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ t('devices.platform') }}</dt>
                <dd class="mt-1 text-sm capitalize text-slate-900 dark:text-white">
                    {{ device.platform ?? '—' }}<span v-if="device.app_version"> · v{{ device.app_version }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ t('devices.mode') }}</dt>
                <dd class="mt-1">
                    <UBadge :color="device.mode === 'offline' ? 'neutral' : 'primary'" variant="subtle"
                            :label="device.mode === 'offline' ? t('dashboard.mode_offline') : t('dashboard.mode_online')" />
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ t('devices.last_seen') }}</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ fmt(device.last_seen_at) }}</dd>
            </div>
        </dl>

        <h2 class="mt-8 mb-3 text-lg font-semibold text-slate-900 dark:text-white">{{ t('sync.history') }}</h2>

        <DataTable
            :columns="columns" :rows="logs" :loading="loading" :error="error"
            :empty-text="t('sync.empty')" empty-icon="i-lucide-refresh-cw"
            min-width="32rem" @retry="load()"
        >
            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-slate-600 dark:text-slate-300">{{ fmt(row.created_at) }}</span>
            </template>
            <template #cell-records_count="{ row }">
                <span class="tabular-nums text-slate-600 dark:text-slate-300">
                    {{ row.synced_count }} / {{ row.records_count }}
                </span>
            </template>
            <template #cell-status="{ row }">
                <UBadge :color="statusColor[row.status] ?? 'neutral'" variant="subtle"
                        :label="t(`sync.status_${row.status}`)" />
            </template>
        </DataTable>
    </div>
</template>
