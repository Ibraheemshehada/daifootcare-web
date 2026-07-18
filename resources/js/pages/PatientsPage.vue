<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';

const { t, d } = useI18n();
const { data, loading, error, load } = useApiResource('/patients');

const patients = computed(() => data.value?.data ?? []);

function formatDate(value) {
    if (!value) return t('common.never');
    return d(new Date(value), 'date');
}
</script>

<template>
    <div>
        <PageHeader :title="t('patients.title')" :subtitle="t('patients.subtitle')" />

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

        <TableSkeleton v-if="loading" :columns="4" />

        <div
            v-else-if="patients.length"
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="border-b border-slate-200 dark:border-slate-800">
                    <tr class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.patient') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('login.email') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('dashboard.scans_total') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.captured') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                        v-for="patient in patients"
                        :key="patient.id"
                        class="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50"
                    >
                        <td class="px-4 py-3">
                            <RouterLink
                                :to="{ name: 'patient-detail', params: { id: patient.id } }"
                                class="font-medium text-cyan-800 hover:underline dark:text-cyan-300"
                            >
                                {{ patient.user?.name ?? '—' }}
                            </RouterLink>
                            <!-- A clinician must be able to tell at a glance that a
                                 record has no identified person behind it. -->
                            <UBadge
                                v-if="patient.user?.is_guest"
                                color="neutral" variant="subtle" size="xs" class="ms-2"
                                :label="t('patients.guest')"
                            />
                            <UBadge
                                v-else-if="patient.user?.claimed_at"
                                color="success" variant="subtle" size="xs" class="ms-2"
                                :label="t('patients.claimed')"
                            />
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                            <template v-if="patient.user?.email">{{ patient.user.email }}</template>
                            <code v-else-if="patient.user?.guest_device_uuid" class="text-xs">
                                {{ patient.user.guest_device_uuid.slice(0, 8) }}…
                            </code>
                            <template v-else>—</template>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                            {{ patient.wound_scans_count ?? 0 }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                            {{ formatDate(patient.last_scan_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700"
        >
            <UIcon name="i-lucide-users" class="mx-auto size-8 text-slate-400" />
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ t('patients.empty') }}</p>
        </div>
    </div>
</template>
