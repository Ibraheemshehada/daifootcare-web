<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';

const { t } = useI18n();
const { data, loading, error, load } = useApiResource('/dashboard/stats');

const stats = computed(() => data.value ?? {});

const cards = computed(() => [
    { label: t('dashboard.patients'), value: stats.value.patients, icon: 'i-lucide-users', accent: 'cyan' },
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
