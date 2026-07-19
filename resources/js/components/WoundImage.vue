<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

/**
 * A wound photograph.
 *
 * Fetched as a blob rather than pointed at with a plain <img src>, because the
 * image endpoint authorises every read and the dashboard authenticates with a
 * bearer token — a bare src would arrive without it and 404. The object URL is
 * revoked when this unmounts so a patient's photograph is not left resident
 * after the clinician has navigated away.
 */
const props = defineProps({
    scanId: { type: [Number, String], required: true },
    hasImage: { type: Boolean, default: false },
    alt: { type: String, default: '' },
})

const { t } = useI18n()

const url = ref(null)
const loading = ref(false)
const failed = ref(false)

function release() {
    if (url.value) {
        URL.revokeObjectURL(url.value)
        url.value = null
    }
}

async function load() {
    release()
    failed.value = false

    if (!props.hasImage) return

    loading.value = true
    try {
        const res = await api.get(`/wound-scans/${props.scanId}/image`, {
            responseType: 'blob',
        })
        url.value = URL.createObjectURL(res.data)
    } catch {
        // A missing or forbidden image is not worth an error dialog — the row
        // is still useful without it.
        failed.value = true
    } finally {
        loading.value = false
    }
}

watch(() => [props.scanId, props.hasImage], load, { immediate: true })
onBeforeUnmount(release)
</script>

<template>
    <div
        class="relative flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-800/50"
    >
        <img
            v-if="url"
            :src="url"
            :alt="alt || t('scans.image_alt')"
            class="h-full w-full object-cover"
            loading="lazy"
        />

        <UIcon
            v-else-if="loading"
            name="i-lucide-loader-circle"
            class="size-5 animate-spin text-slate-400"
        />

        <!-- "No photo" and "could not load it" are different facts, and a
             clinician deciding whether to chase a missing image needs to know
             which one this is. -->
        <div v-else class="px-1 text-center">
            <UIcon
                :name="failed ? 'i-lucide-image-off' : 'i-lucide-image'"
                class="size-5 text-slate-400"
            />
            <p class="mt-1 text-[10px] leading-tight text-slate-500 dark:text-slate-400">
                {{ failed ? t('scans.image_failed') : t('scans.no_image') }}
            </p>
        </div>
    </div>
</template>
