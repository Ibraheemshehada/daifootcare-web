<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApiResource } from '@/composables/useApiResource';
import { useAuthStore } from '@/stores/auth';
import api from '@/lib/api';
import PageHeader from '@/components/PageHeader.vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';

const { t } = useI18n();
const auth = useAuthStore();
const { data, loading, error, load } = useApiResource('/users');
const rows = computed(() => data.value?.data ?? []);

const saving = ref(null);
const notice = ref(null);

const columns = computed(() => [
    { key: 'name', label: t('users.name') },
    { key: 'email', label: t('login.email') },
    { key: 'devices_count', label: t('nav.devices') },
    { key: 'role', label: t('users.role') },
]);

const roles = ['admin', 'doctor', 'patient'];
const roleColor = { admin: 'primary', doctor: 'success', patient: 'neutral' };

async function changeRole(user, role) {
    if (role === user.role) return;

    saving.value = user.id;
    notice.value = null;

    try {
        await api.patch(`/users/${user.id}/role`, { role });
        await load();
    } catch (e) {
        // The server refuses self-demotion, promoting a guest, and removing the
        // last admin. Surface its reason rather than a generic failure, since
        // each one tells the admin something different about what to do next.
        notice.value = e.response?.data?.message ?? t('common.error');
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('users.title')" :subtitle="t('users.subtitle')" />

        <UAlert
            v-if="notice"
            color="warning"
            variant="soft"
            icon="i-lucide-alert-triangle"
            :description="notice"
            class="mb-6"
        />

        <DataTable
            :columns="columns" :rows="rows" :loading="loading" :error="error"
            :empty-text="t('users.empty')" empty-icon="i-lucide-users" @retry="load()"
        >
            <template #cell-name="{ row }">
                <span class="font-medium text-slate-900 dark:text-white">{{ row.name }}</span>
                <UBadge v-if="row.is_guest" color="neutral" variant="subtle" size="xs" class="ms-2"
                        :label="t('patients.guest')" />
                <UBadge v-if="row.id === auth.user?.id" color="primary" variant="subtle" size="xs" class="ms-2"
                        :label="t('users.you')" />
            </template>

            <template #cell-email="{ row }">
                <span class="text-slate-600 dark:text-slate-300">{{ row.email ?? '—' }}</span>
            </template>

            <template #cell-devices_count="{ row }">
                <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ row.devices_count }}</span>
            </template>

            <template #cell-role="{ row }">
                <div class="flex items-center gap-2">
                    <UBadge :color="roleColor[row.role] ?? 'neutral'" variant="subtle"
                            :label="t(`users.role_${row.role}`)" />
                    <!-- Disabled on your own row: the server refuses it anyway, and
                         offering a control that always fails is worse than hiding it. -->
                    <select
                        class="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-900 disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                        :value="row.role"
                        :disabled="saving === row.id || row.id === auth.user?.id"
                        :aria-label="t('users.change_role', { name: row.name })"
                        @change="changeRole(row, $event.target.value)"
                    >
                        <option v-for="r in roles" :key="r" :value="r">{{ t(`users.role_${r}`) }}</option>
                    </select>
                </div>
            </template>
        </DataTable>

        <Pagination :meta="data" :loading="loading" @change="(p) => load({ page: p })" />
    </div>
</template>
