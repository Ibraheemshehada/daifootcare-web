<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';

/**
 * Where to read a scan's photograph.
 *
 * Keyed by local_uuid — the identifier the device assigned and syncs on — not
 * the server's row id, so the same URL works whichever side you start from.
 */
function imageUrl(scan) {
    return `/api/v1/wound-scans/${scan.local_uuid}/image`;
}
import Pagination from '@/components/Pagination.vue';

const { t, d } = useI18n();
const { data, loading, error, load } = useApiResource('/wound-scans');

const scans = computed(() => data.value?.data ?? []);

/**
 * Mirrors the mobile app's risk rule so a clinician sees the same badge in both
 * places. If these ever diverge, the dashboard is lying about the app's output.
 */
function riskOf(scan) {
    if (scan.infection_present && scan.ischaemia_present) {
        return { key: 'risk.high', color: 'error' };
    }
    if (scan.infection_present) {
        return { key: 'risk.infection', color: 'error' };
    }
    if (scan.ischaemia_present) {
        return { key: 'risk.ischaemia', color: 'warning' };
    }
    if (scan.infection_present === null && scan.ischaemia_present === null) {
        return { key: 'risk.unknown', color: 'neutral' };
    }
    return { key: 'risk.normal', color: 'success' };
}

/** A 0×0 result means segmentation found no wound — not a measurement of zero. */
function sizeLabel(scan) {
    if (!scan.length_cm && !scan.width_cm) {
        return t('scans.no_wound');
    }
    return `${(scan.length_cm ?? 0).toFixed(1)} × ${(scan.width_cm ?? 0).toFixed(1)} cm`;
}
</script>

<template>
    <div>
        <PageHeader :title="t('scans.title')" :subtitle="t('scans.subtitle')" />

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

        <TableSkeleton v-if="loading" :columns="8" />

        <div
            v-else-if="scans.length"
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <table class="w-full min-w-[60rem] text-sm">
                <thead class="border-b border-slate-200 dark:border-slate-800">
                    <tr class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.photo') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.captured') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.patient') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.size') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.area') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.tissue') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.risk') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ t('scans.source') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                        v-for="scan in scans"
                        :key="scan.id"
                        class="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50"
                    >
                        <!--
                            The wound photograph. Served through the API rather
                            than a public URL: the file sits on the private disk
                            because a wound image tied to a named patient must
                            not be reachable by guessing a path, so the request
                            carries the session and the server authorises it.
                        -->
                        <td class="px-4 py-3">
                            <a
                                v-if="scan.local_uuid && scan.has_image"
                                :href="imageUrl(scan)"
                                target="_blank"
                                rel="noopener"
                                class="block h-12 w-12 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700"
                                :title="t('scans.viewPhoto')"
                            >
                                <img
                                    :src="imageUrl(scan)"
                                    :alt="t('scans.photo')"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                />
                            </a>
                            <span
                                v-else
                                class="flex h-12 w-12 items-center justify-center rounded-lg border border-dashed border-slate-300 text-xs text-slate-400 dark:border-slate-700"
                                :title="t('scans.noPhoto')"
                            >—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-300">
                            {{ d(new Date(scan.captured_at), 'short') }}
                        </td>
                        <td class="px-4 py-3 text-slate-900 dark:text-white">
                            {{ scan.patient?.user?.name ?? '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                            {{ sizeLabel(scan) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                            {{ scan.area_cm2 ? `${scan.area_cm2.toFixed(2)} cm²` : '—' }}
                        </td>
                        <!-- Every tissue found, not just the headline: a bed
                             usually holds several, and the rest of the answer
                             used to be discarded before it reached here. -->
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                            <span :title="scan.tissue_summary ?? ''">
                                {{ scan.tissue_summary ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <UBadge
                                :color="riskOf(scan).color"
                                variant="subtle"
                                :label="t(riskOf(scan).key)"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <UBadge
                                :color="scan.source === 'online' ? 'primary' : 'neutral'"
                                variant="subtle"
                                :label="scan.source === 'online' ? t('dashboard.mode_online') : t('dashboard.mode_offline')"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700"
        >
            <UIcon name="i-lucide-activity" class="mx-auto size-8 text-slate-400" />
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ t('scans.empty') }}</p>
        </div>
        <Pagination :meta="data" :loading="loading" @change="(p) => load({ page: p })" />

    </div>
</template>
