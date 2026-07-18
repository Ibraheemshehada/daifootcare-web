<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';

const { t, d } = useI18n();
const { data, loading, error, load } = useApiResource('/devices');

const devices = computed(() => data.value?.data ?? []);

function formatDate(value) {
    if (!value) return t('common.never');
    return d(new Date(value), 'short');
}
</script>

<template>
    <div>
        <PageHeader :title="t('devices.title')" :subtitle="t('devices.subtitle')" />

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

        <TableSkeleton v-if="loading" :columns="6" />

        <div
            v-else-if="devices.length"
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <table class="w-full min-w-[52rem] text-sm">
                <thead class="border-b border-slate-200 text-start dark:border-slate-800">
                    <tr class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">
                        <th class="px-4 py-3 text-start font-medium">{{ t('devices.device') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('devices.owner') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('devices.platform') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('devices.mode') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('devices.models') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('devices.last_seen') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                        v-for="device in devices"
                        :key="device.id"
                        class="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50"
                    >
                        <td class="px-4 py-3">
                            <code class="text-xs text-slate-600 dark:text-slate-400">
                                {{ device.device_uuid.slice(0, 8) }}…
                            </code>
                            <p v-if="device.app_version" class="mt-0.5 text-xs text-slate-400">
                                v{{ device.app_version }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-slate-900 dark:text-white">
                            {{ device.user?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 capitalize text-slate-600 dark:text-slate-300">
                            {{ device.platform }}
                        </td>
                        <td class="px-4 py-3">
                            <UBadge
                                :color="device.mode === 'offline' ? 'neutral' : 'primary'"
                                variant="subtle"
                                :label="device.mode === 'offline' ? t('dashboard.mode_offline') : t('dashboard.mode_online')"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <UBadge
                                :color="device.models_downloaded_at ? 'success' : 'neutral'"
                                variant="subtle"
                                :label="device.models_downloaded_at ? t('devices.downloaded') : t('devices.not_downloaded')"
                            />
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                            {{ formatDate(device.last_seen_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700"
        >
            <UIcon name="i-lucide-smartphone" class="mx-auto size-8 text-slate-400" />
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ t('devices.empty') }}</p>
        </div>
    </div>
</template>
