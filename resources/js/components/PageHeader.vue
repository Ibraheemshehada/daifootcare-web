<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">
            {{ title }}
        </h1>
        <p v-if="subtitle" class="mt-1 text-sm text-slate-600 dark:text-slate-400">
            {{ subtitle }}
        </p>
    </div>
</template>
