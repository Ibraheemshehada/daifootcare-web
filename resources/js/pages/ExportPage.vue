<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '@/lib/api';
import PageHeader from '@/components/PageHeader.vue';

const { t } = useI18n();

const busy = ref(null);
const failed = ref(null);

const exports = [
    { type: 'wound-scans', icon: 'i-lucide-scan-line' },
    { type: 'glucose', icon: 'i-lucide-droplet' },
    { type: 'sus', icon: 'i-lucide-clipboard-list' },
    { type: 'self-care', icon: 'i-lucide-list-checks' },
];

/**
 * Downloads through the API client rather than a plain link, because the export
 * route is token-authenticated and an <a href> carries no Authorization header.
 */
async function download(type) {
    busy.value = type;
    failed.value = null;

    try {
        const res = await api.get(`/export/${type}`, { responseType: 'blob' });
        const url = URL.createObjectURL(new Blob([res.data], { type: 'text/csv;charset=utf-8' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `diafootcare-${type}-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        // Revoking immediately can cancel the download in some browsers.
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
        failed.value = t('export.failed');
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('export.title')" :subtitle="t('export.subtitle')" />

        <!--
            Stated plainly because a CSV leaves every technical control behind:
            once downloaded, the file is identifiable clinical data sitting on
            someone's laptop, and each download is logged server-side.
        -->
        <UAlert
            color="warning"
            variant="soft"
            icon="i-lucide-shield-alert"
            :title="t('export.warning_title')"
            :description="t('export.warning_body')"
            class="mb-6"
        />

        <UAlert v-if="failed" color="error" variant="soft" icon="i-lucide-alert-circle"
                :description="failed" class="mb-6" />

        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="e in exports"
                :key="e.type"
                class="flex items-start gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
            >
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-cyan-50 text-cyan-800 dark:bg-cyan-950 dark:text-cyan-200">
                    <UIcon :name="e.icon" class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold text-slate-900 dark:text-white">
                        {{ t(`export.${e.type}_title`) }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        {{ t(`export.${e.type}_body`) }}
                    </p>

                    <UButton
                        class="mt-4"
                        size="sm"
                        icon="i-lucide-download"
                        :loading="busy === e.type"
                        :label="t('export.download')"
                        @click="download(e.type)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
