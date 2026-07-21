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

// Creating a user is the same privilege as granting a role, so it lives on this
// page rather than somewhere a clinician might reach it.
const showCreate = ref(false);
const creating = ref(false);
const createError = ref(null);
const blank = () => ({ name: '', email: '', password: '', role: 'doctor', locale: 'en' });
const form = ref(blank());

async function createUser() {
    creating.value = true;
    createError.value = null;
    notice.value = null;

    try {
        const { data: created } = await api.post('/users', form.value);
        form.value = blank();
        showCreate.value = false;
        await load();
        notice.value = t('users.created', { email: created.user.email });
    } catch (e) {
        // Show the server's own reason. "That email is already registered" and
        // "the password is too short" need different actions from the admin,
        // and a generic failure hides which one happened.
        const errs = e.response?.data?.errors;
        createError.value = errs
            ? Object.values(errs).flat().join(' ')
            : (e.response?.data?.message ?? t('common.error'));
    } finally {
        creating.value = false;
    }
}

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

        <div class="mb-6 flex justify-end">
            <UButton
                :icon="showCreate ? 'i-lucide-x' : 'i-lucide-user-plus'"
                :color="showCreate ? 'neutral' : 'primary'"
                :variant="showCreate ? 'outline' : 'solid'"
                :label="showCreate ? t('common.cancel') : t('users.add')"
                @click="showCreate = !showCreate; createError = null"
            />
        </div>

        <form
            v-if="showCreate"
            class="mb-8 rounded-xl border border-slate-200 p-5 dark:border-slate-800"
            @submit.prevent="createUser"
        >
            <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">{{ t('users.add') }}</h3>

            <UAlert
                v-if="createError" color="error" variant="soft" icon="i-lucide-alert-triangle"
                :description="createError" class="mb-4"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <UFormField :label="t('users.name')" required>
                    <UInput v-model="form.name" required autocomplete="off" class="w-full" />
                </UFormField>

                <UFormField :label="t('login.email')" required>
                    <UInput v-model="form.email" type="email" required autocomplete="off" class="w-full" />
                </UFormField>

                <UFormField :label="t('login.password')" :hint="t('users.password_hint')" required>
                    <UInput
                        v-model="form.password" type="password" required minlength="12"
                        autocomplete="new-password" class="w-full"
                    />
                </UFormField>

                <UFormField :label="t('users.role')" required>
                    <select
                        v-model="form.role"
                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                    >
                        <option v-for="r in roles" :key="r" :value="r">{{ t(`users.role_${r}`) }}</option>
                    </select>
                </UFormField>
            </div>

            <!-- Said before the button, not after: an admin created here has
                 full access to every patient record. -->
            <p v-if="form.role === 'admin'" class="mt-4 text-sm text-amber-700 dark:text-amber-400">
                {{ t('users.admin_warning') }}
            </p>

            <div class="mt-5 flex items-center gap-3">
                <UButton type="submit" :loading="creating" :label="t('users.create')" />
                <span class="text-sm text-slate-500 dark:text-slate-400">{{ t('users.password_note') }}</span>
            </div>
        </form>

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
