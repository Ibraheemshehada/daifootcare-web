<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';

const { t } = useI18n();
const { data, loading, error, load } = useApiResource('/study/summary');

const s = computed(() => data.value ?? {});

const cards = computed(() => [
    { label: t('study.responses'), value: s.value.sus?.responses, icon: 'i-lucide-clipboard-list', accent: 'slate' },
    { label: t('study.sus_mean'), value: s.value.sus?.mean, icon: 'i-lucide-gauge', accent: 'cyan' },
    { label: t('study.sus_median'), value: s.value.sus?.median, icon: 'i-lucide-align-center', accent: 'cyan' },
    { label: t('study.active_7d'), value: s.value.engagement?.active_participants_7d, icon: 'i-lucide-users', accent: 'emerald' },
    { label: t('study.app_opens'), value: s.value.engagement?.app_opens, icon: 'i-lucide-smartphone', accent: 'slate' },
]);

const bandOrder = ['excellent', 'good', 'ok', 'poor'];
const bandColor = { excellent: 'success', good: 'success', ok: 'warning', poor: 'error' };

const totalBanded = computed(() =>
    bandOrder.reduce((n, b) => n + (s.value.sus?.bands?.[b] ?? 0), 0)
);
</script>

<template>
    <div>
        <PageHeader :title="t('study.title')" :subtitle="t('study.subtitle')" />

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

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <StatCard v-for="(c, i) in cards" :key="c.label" v-bind="c" :loading="loading" :delay="i * 0.04" />
        </div>

        <TableSkeleton v-if="loading" class="mt-8" :columns="3" />

        <template v-else>
            <div class="mt-8 grid gap-5 lg:grid-cols-2">
                <!-- SUS distribution -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('study.distribution') }}</h2>
                    <!-- 68 is the conventional SUS average, not a pass mark; saying so
                         stops the number being read as a percentage score. -->
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('study.benchmark_note') }}</p>

                    <ul class="mt-4 space-y-3">
                        <li v-for="b in bandOrder" :key="b" class="flex items-center gap-3">
                            <span class="w-20 shrink-0 text-sm text-slate-700 dark:text-slate-300">
                                {{ t(`study.band_${b}`) }}
                            </span>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                <div
                                    class="h-full rounded-full bg-cyan-600 transition-[width] duration-500"
                                    :style="{ width: totalBanded ? `${((s.sus?.bands?.[b] ?? 0) / totalBanded) * 100}%` : '0%' }"
                                />
                            </div>
                            <span class="w-8 shrink-0 text-end text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                {{ s.sus?.bands?.[b] ?? 0 }}
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Satisfaction -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ t('study.satisfaction') }}</h2>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('study.satisfaction_note') }}</p>
                    <dl class="mt-4 space-y-3">
                        <div v-for="k in ['ease_of_use', 'usefulness', 'would_continue']" :key="k"
                             class="flex items-center justify-between gap-4">
                            <dt class="text-sm text-slate-700 dark:text-slate-300">{{ t(`study.${k}`) }}</dt>
                            <dd class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ s.satisfaction?.[k] ?? '—' }}<span class="text-slate-500"> / 5</span>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <!-- Feature utilisation -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">{{ t('study.top_features') }}</h2>
                    <ul v-if="s.engagement?.top_features?.length" class="space-y-2">
                        <li v-for="f in s.engagement.top_features" :key="f.target"
                            class="flex items-center justify-between gap-4 text-sm">
                            <span class="truncate text-slate-700 dark:text-slate-300">{{ f.target }}</span>
                            <span class="tabular-nums text-slate-600 dark:text-slate-400">{{ f.opens }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('study.no_engagement') }}</p>
                </div>

                <!-- Consent split -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-1 text-sm font-semibold text-slate-900 dark:text-white">{{ t('study.consent') }}</h2>
                    <p class="mb-3 text-xs text-slate-600 dark:text-slate-400">{{ t('study.consent_note') }}</p>
                    <ul v-if="s.consent?.by_version?.length" class="space-y-2">
                        <li v-for="c in s.consent.by_version" :key="c.version"
                            class="flex items-center justify-between gap-4 text-sm">
                            <UBadge color="primary" variant="subtle" :label="`v${c.version}`" />
                            <span class="tabular-nums text-slate-600 dark:text-slate-400">
                                {{ c.participants }} {{ t('study.participants') }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-slate-600 dark:text-slate-400">{{ t('study.no_consent') }}</p>
                </div>
            </div>
        </template>
    </div>
</template>
