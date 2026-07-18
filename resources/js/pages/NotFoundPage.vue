<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center gap-4 bg-slate-50 p-4 dark:bg-slate-950">
        <UIcon name="i-lucide-map-pin-off" class="size-10 text-slate-400" />
        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('notfound.title') }}</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('notfound.body') }}</p>
        <UButton :to="{ name: 'dashboard' }" :label="t('notfound.back')" />
    </div>
</template>
