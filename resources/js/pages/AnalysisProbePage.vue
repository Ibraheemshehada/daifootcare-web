<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/PageHeader.vue';
import api from '@/lib/api';

const { t } = useI18n();

const file = ref(null);
const preview = ref(null);
const result = ref(null);
const busy = ref(false);
const error = ref(null);

function choose(e) {
    const f = e.target.files?.[0];
    if (!f) return;
    file.value = f;
    result.value = null;
    error.value = null;
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = URL.createObjectURL(f);
}

async function run() {
    if (!file.value) return;
    busy.value = true;
    error.value = null;
    result.value = null;
    try {
        const form = new FormData();
        form.append('image', file.value);
        const { data } = await api.post('/analysis/probe', form, {
            headers: { 'Content-Type': 'multipart/form-data' },
            timeout: 120000,
        });
        result.value = data.analysis;
    } catch (e) {
        error.value = e?.response?.data?.message ?? t('probe.failed');
    } finally {
        busy.value = false;
    }
}

const overlaySrc = computed(() =>
    result.value?.overlay_jpeg_b64
        ? `data:image/jpeg;base64,${result.value.overlay_jpeg_b64}`
        : null,
);

// Sorted most likely first, and every class is shown - not only the ones that
// cleared their threshold. The head is multi-label and a wound bed holds several
// tissues at once, so naming one winner throws away most of the answer; and a
// class sitting just under its threshold is exactly what someone checking the
// model wants to see.
const tissues = computed(() =>
    [...(result.value?.tissue_findings ?? [])].sort(
        (a, b) => b.probability - a.probability,
    ),
);

const angleTone = computed(() => {
    const t = result.value?.tilt_deg;
    if (t == null) return null;
    return t <= 30 ? 'success' : t <= 40 ? 'warning' : 'error';
});
</script>

<template>
    <div>
        <PageHeader :title="t('probe.title')" :subtitle="t('probe.subtitle')" />

        <!-- Said plainly and first. Someone using this to sanity-check a model
             must not wonder later whether they polluted the study data. -->
        <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900
                    dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
            {{ t('probe.nothing_saved') }}
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                <label class="block cursor-pointer rounded-xl border-2 border-dashed border-slate-300
                              p-6 text-center hover:border-sky-400 dark:border-slate-600">
                    <input type="file" accept="image/*" class="hidden" @change="choose" />
                    <div class="text-sm text-slate-600 dark:text-slate-300">
                        {{ file ? file.name : t('probe.choose') }}
                    </div>
                </label>

                <img v-if="preview" :src="preview" alt=""
                     class="mt-4 max-h-[42vh] w-full rounded-lg object-contain" />

                <UButton class="mt-4 w-full justify-center" size="lg"
                         :loading="busy" :disabled="!file || busy"
                         :label="busy ? t('probe.running') : t('probe.run')"
                         @click="run" />

                <p v-if="error" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700
                                       dark:bg-red-950/40 dark:text-red-300">
                    {{ error }}
                </p>
            </div>

            <div v-if="result"
                 class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                <!-- The mask first: it is the thing that says whether any number
                     below is worth reading. -->
                <img v-if="overlaySrc" :src="overlaySrc" :alt="t('probe.mask')"
                     class="mb-4 max-h-[42vh] w-full rounded-lg object-contain" />
                <p v-else class="mb-4 rounded-lg border border-dashed border-slate-300 p-4
                                 text-center text-sm text-slate-500 dark:border-slate-600">
                    {{ t('probe.no_mask') }}
                </p>

                <dl class="grid grid-cols-2 gap-3">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('probe.size') }}</dt>
                        <dd class="text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ (!result.length && !result.width) ? t('probe.no_wound')
                                : `${result.length?.toFixed(2)} × ${result.width?.toFixed(2)} cm` }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('probe.area') }}</dt>
                        <dd class="text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ result.area ? `${result.area.toFixed(2)} cm²` : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('probe.scale') }}</dt>
                        <dd class="text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ result.pixels_per_cm
                                ? `${result.pixels_per_cm.toFixed(1)} px/cm`
                                : t('probe.no_ring') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('probe.angle') }}</dt>
                        <dd class="mt-0.5">
                            <UBadge v-if="result.tilt_deg != null" :color="angleTone" variant="subtle"
                                    :label="`${Math.round(result.tilt_deg)}°`" />
                            <span v-else class="text-sm text-slate-500">—</span>
                        </dd>
                    </div>
                </dl>

                <h3 class="mt-5 text-sm font-semibold text-slate-900 dark:text-white">
                    {{ t('probe.tissues') }}
                </h3>
                <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                    {{ t('probe.tissues_hint') }}
                </p>
                <ul class="space-y-1.5">
                    <li v-for="f in tissues" :key="f.type"
                        class="flex items-center gap-3 text-sm">
                        <span class="w-28 shrink-0 capitalize text-slate-700 dark:text-slate-200">
                            {{ f.type }}
                        </span>
                        <span class="relative h-2 flex-1 rounded-full bg-slate-200 dark:bg-slate-700">
                            <span class="absolute inset-y-0 left-0 rounded-full"
                                  :class="f.is_present ? 'bg-emerald-500' : 'bg-slate-400'"
                                  :style="{ width: `${Math.round(f.probability * 100)}%` }" />
                            <!-- Where the threshold sits, so a class just under
                                 it is visible as "nearly" rather than "no". -->
                            <span class="absolute inset-y-[-3px] w-px bg-slate-900 dark:bg-white"
                                  :style="{ left: `${Math.round((f.threshold ?? 0.5) * 100)}%` }" />
                        </span>
                        <span class="w-24 shrink-0 text-right tabular-nums text-slate-600 dark:text-slate-300">
                            {{ (f.probability * 100).toFixed(0) }}%
                            <span class="text-slate-400">/ {{ ((f.threshold ?? 0.5) * 100).toFixed(0) }}</span>
                        </span>
                    </li>
                </ul>

                <dl class="mt-5 grid grid-cols-2 gap-3">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('probe.infection') }}</dt>
                        <dd class="text-sm text-slate-900 dark:text-white">{{ result.infection }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ t('probe.ischaemia') }}</dt>
                        <dd class="text-sm text-slate-900 dark:text-white">{{ result.ischaemia }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</template>
