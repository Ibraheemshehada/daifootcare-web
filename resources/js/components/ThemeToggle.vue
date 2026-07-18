<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/composables/useTheme';

const { t } = useI18n();
const { theme, cycleTheme } = useTheme();

const icon = computed(() => ({
    light: 'i-lucide-sun',
    dark: 'i-lucide-moon',
    system: 'i-lucide-monitor',
}[theme.value] ?? 'i-lucide-monitor'));

// The label names the *current* mode, and aria-label says what tapping does —
// an icon-only control with no accessible name is unusable with a screen reader.
const label = computed(() => t(`theme.${theme.value}`));
</script>

<template>
    <button
        type="button"
        class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
        :aria-label="t('theme.toggle', { mode: label })"
        :title="label"
        @click="cycleTheme"
    >
        <UIcon :name="icon" class="size-4 shrink-0" />
        <span class="hidden sm:inline">{{ label }}</span>
    </button>
</template>
