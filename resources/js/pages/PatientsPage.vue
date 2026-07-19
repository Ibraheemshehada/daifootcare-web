<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import PageHeader from '@/components/PageHeader.vue';
import PatientCard from '@/components/PatientCard.vue';
import Pagination from '@/components/Pagination.vue';

/**
 * The monitoring board.
 *
 * Cards rather than rows, because the question this page answers is "who needs
 * me today", and a table makes you read every line to find out. The needs-
 * attention filter is the page's real function; search is the fallback for when
 * you already know who you are looking for.
 */
const { t } = useI18n();

const query = ref('');
const onlyAttention = ref(false);

const { data, loading, error, load, reset } = useApiResource('/patients');

// Debounced so typing does not fire a request per keystroke. A new search
// returns to page 1: staying on page 3 of a narrower result set shows an empty
// page, which reads as "no matches" when there are plenty.
let timer;
watch(query, (q) => {
    clearTimeout(timer);
    timer = setTimeout(() => reset(q ? { q, page: 1 } : { page: 1 }), 300);
});

const all = computed(() => data.value?.data ?? []);

/** Filtering client-side: the flags are computed per page by the API already. */
const patients = computed(() =>
    onlyAttention.value ? all.value.filter((p) => (p.attention ?? []).length > 0) : all.value
);

const attentionCount = computed(() => all.value.filter((p) => (p.attention ?? []).length > 0).length);
const total = computed(() => data.value?.total ?? all.value.length);
</script>

<template>
    <div>
        <PageHeader :title="t('patients.title')" :subtitle="t('patients.subtitle')" />

        <!-- Controls -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <UInput
                v-model="query"
                icon="i-lucide-search"
                :placeholder="t('patients.search')"
                :aria-label="t('patients.search')"
                class="w-full sm:w-72"
            />

            <button
                type="button"
                class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                :class="onlyAttention
                    ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                    : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                :aria-pressed="onlyAttention"
                @click="onlyAttention = !onlyAttention"
            >
                <UIcon name="i-lucide-siren" class="size-4 shrink-0" />
                {{ t('patients.needs_attention') }}
                <UBadge :color="attentionCount ? 'error' : 'neutral'" variant="subtle" size="xs"
                        :label="String(attentionCount)" />
            </button>

            <span class="ms-auto text-sm text-slate-600 dark:text-slate-400">
                {{ t('patients.showing', { shown: patients.length, total }) }}
            </span>
        </div>

        <UAlert
            v-if="error" color="error" variant="soft" icon="i-lucide-alert-circle"
            :description="t('common.error')" class="mb-6"
        >
            <template #actions>
                <UButton color="error" variant="outline" size="xs" :label="t('common.retry')" @click="load()" />
            </template>
        </UAlert>

        <!-- Skeleton cards, so the layout does not jump when data lands. -->
        <div v-if="loading" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="i in 6" :key="i"
                class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
            >
                <div class="flex items-center gap-3">
                    <USkeleton class="size-10 rounded-full" />
                    <div class="flex-1 space-y-2">
                        <USkeleton class="h-4 w-32" />
                        <USkeleton class="h-3 w-40" />
                    </div>
                </div>
                <USkeleton class="mt-4 h-5 w-24" />
                <USkeleton class="mt-4 h-9 w-full" />
                <USkeleton class="mt-4 h-3 w-full" />
            </div>
        </div>

        <div v-else-if="patients.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <PatientCard v-for="p in patients" :key="p.id" :patient="p" />
        </div>

        <!-- Hidden while the attention filter is on: it filters the current page
             client-side, so page numbers would no longer describe what is shown. -->
        <Pagination
            v-if="!onlyAttention"
            :meta="data" :loading="loading" @change="(p) => load({ page: p })"
        />

        <div v-if="!patients.length && !loading"
             class="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
            <UIcon
                :name="onlyAttention ? 'i-lucide-shield-check' : 'i-lucide-users'"
                class="mx-auto size-8 text-slate-400"
            />
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">
                {{ onlyAttention ? t('patients.none_need_attention')
                    : (query ? t('patients.no_matches') : t('patients.empty')) }}
            </p>
        </div>
    </div>
</template>
