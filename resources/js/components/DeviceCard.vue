<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * One install, summarised.
 *
 * Sync health is on the card because a device that stopped reporting is a
 * clinical fact, not an IT detail: it means this patient's records are sitting
 * on a phone and the chart above is out of date. That has to be visible where
 * the chart is read, not behind another page.
 */
const props = defineProps({
    device: { type: Object, required: true },
});

const { t, d } = useI18n();

const lastSeen = computed(() =>
    props.device.last_seen_at ? d(new Date(props.device.last_seen_at), 'short') : t('common.never')
);

const health = computed(() => {
    if (props.device.is_stale) {
        return { color: 'warning', icon: 'i-lucide-wifi-off', key: 'devices.stale' };
    }
    if (props.device.failed_batches > 0) {
        return { color: 'error', icon: 'i-lucide-alert-triangle', key: 'devices.sync_failures' };
    }
    return { color: 'success', icon: 'i-lucide-check-circle-2', key: 'devices.healthy' };
});
</script>

<template>
    <RouterLink
        :to="{ name: 'device-detail', params: { uuid: device.device_uuid } }"
        class="block rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-cyan-300 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-cyan-800 dark:hover:bg-slate-800/50"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >
                    <UIcon
                        :name="device.platform === 'ios' ? 'i-lucide-smartphone' : 'i-lucide-tablet-smartphone'"
                        class="size-4"
                    />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium capitalize text-slate-900 dark:text-white">
                        {{ device.platform }}
                        <span v-if="device.app_version" class="font-normal text-slate-500">
                            · v{{ device.app_version }}
                        </span>
                    </p>
                    <code class="text-xs text-slate-500">{{ device.device_uuid.slice(0, 8) }}…</code>
                </div>
            </div>

            <!-- Status is colour + icon + words, never colour alone. -->
            <UBadge :color="health.color" variant="subtle" :icon="health.icon" :label="t(health.key)" />
        </div>

        <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
            <div>
                <dt class="text-[0.65rem] uppercase tracking-wide text-slate-500">{{ t('devices.scans_sent') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">
                    {{ device.scans_count ?? 0 }}
                </dd>
            </div>
            <div>
                <dt class="text-[0.65rem] uppercase tracking-wide text-slate-500">{{ t('sync.batches') }}</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">
                    {{ device.batches_count ?? 0 }}
                </dd>
            </div>
            <div>
                <dt class="text-[0.65rem] uppercase tracking-wide text-slate-500">{{ t('sync.failed_batches') }}</dt>
                <dd
                    class="mt-0.5 text-lg font-semibold tabular-nums"
                    :class="device.failed_batches > 0
                        ? 'text-red-700 dark:text-red-400'
                        : 'text-slate-900 dark:text-white'"
                >
                    {{ device.failed_batches ?? 0 }}
                </dd>
            </div>
        </dl>

        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <UBadge
                :color="device.mode === 'offline' ? 'neutral' : 'primary'"
                variant="subtle" size="xs"
                :label="device.mode === 'offline' ? t('dashboard.mode_offline') : t('dashboard.mode_online')"
            />
            <UBadge
                v-if="device.models_downloaded_at"
                color="success" variant="subtle" size="xs" :label="t('devices.downloaded')"
            />
            <span class="ms-auto text-slate-500">{{ t('devices.last_seen') }}: {{ lastSeen }}</span>
        </div>
    </RouterLink>
</template>
