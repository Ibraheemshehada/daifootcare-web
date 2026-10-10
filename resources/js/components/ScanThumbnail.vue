<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthedImage } from '@/composables/useAuthedImage';

/**
 * One scan's row thumbnail.
 *
 * Its own component, not an <img> inline in the table's v-for, because the
 * fetch behind it needs its own composable instance per row — each thumbnail
 * authenticates and loads independently of the others.
 */
const props = defineProps({
    scan: { type: Object, required: true },
});

const { t } = useI18n();

const url = computed(() =>
    props.scan.local_uuid && props.scan.has_image
        ? `/api/v1/wound-scans/${props.scan.local_uuid}/image`
        : null
);

const { src, loading } = useAuthedImage(url);
</script>

<template>
    <img v-if="src"
         :src="src"
         :alt="t('scans.photo')"
         class="h-12 w-12 rounded-lg border border-slate-200 object-cover dark:border-slate-700" />
    <div v-else-if="loading"
         class="h-12 w-12 animate-pulse rounded-lg border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800" />
    <span v-else
          class="flex h-12 w-12 items-center justify-center rounded-lg border border-dashed border-slate-300 text-xs text-slate-400 dark:border-slate-700">
        —
    </span>
</template>
