<script setup>
/**
 * The table shape every list page here shares: skeleton, error+retry, empty
 * state, and a horizontally scrollable body.
 *
 * Extracted because seven pages repeating the same 40 lines is how their empty
 * and loading states quietly drift apart.
 */
import { useI18n } from 'vue-i18n';
import TableSkeleton from '@/components/TableSkeleton.vue';

const props = defineProps({
    columns: { type: Array, required: true },   // [{ key, label, class? }]
    rows: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    error: { type: [Object, Error, null], default: null },
    emptyText: { type: String, default: '' },
    emptyIcon: { type: String, default: 'i-lucide-inbox' },
    minWidth: { type: String, default: '48rem' },
    rowKey: { type: String, default: 'id' },
});

const emit = defineEmits(['retry']);
const { t } = useI18n();
</script>

<template>
    <div>
        <UAlert
            v-if="error"
            color="error"
            variant="soft"
            icon="i-lucide-alert-circle"
            :description="t('common.error')"
            class="mb-6"
        >
            <template #actions>
                <UButton color="error" variant="outline" size="xs"
                         :label="t('common.retry')" @click="emit('retry')" />
            </template>
        </UAlert>

        <TableSkeleton v-if="loading" :columns="columns.length" />

        <div
            v-else-if="rows.length"
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <table class="w-full text-sm" :style="{ minWidth }">
                <thead class="border-b border-slate-200 dark:border-slate-800">
                    <tr class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">
                        <th v-for="c in columns" :key="c.key"
                            class="px-4 py-3 text-start font-medium" :class="c.class">
                            {{ c.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="row in rows" :key="row[rowKey]"
                        class="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td v-for="c in columns" :key="c.key" class="px-4 py-3" :class="c.class">
                            <slot :name="`cell-${c.key}`" :row="row">
                                {{ row[c.key] ?? '—' }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
            <UIcon :name="emptyIcon" class="mx-auto size-8 text-slate-400" />
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ emptyText }}</p>
        </div>
    </div>
</template>
