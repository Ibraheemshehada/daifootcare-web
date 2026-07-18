<script setup>
defineProps({
    rows: { type: Number, default: 6 },
    columns: { type: Number, default: 5 },
});
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 p-4 dark:border-slate-800">
            <USkeleton class="h-4 w-32" />
        </div>

        <div
            v-for="r in rows"
            :key="r"
            class="flex items-center gap-4 border-b border-slate-100 p-4 last:border-0 dark:border-slate-800"
        >
            <USkeleton
                v-for="c in columns"
                :key="c"
                class="h-4"
                :class="c === 1 ? 'w-40' : 'w-24'"
            />
        </div>
    </div>
</template>
