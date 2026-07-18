<script setup>
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/stores/auth';
import { SUPPORTED_LOCALES, applyLocale } from '@/i18n';

const { t, locale } = useI18n();
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = ref({ email: '', password: '' });
const errorMessage = ref(null);

async function submit() {
    errorMessage.value = null;

    try {
        await auth.login(form.value);
        router.push(route.query.redirect ?? { name: 'dashboard' });
    } catch (e) {
        // 422 is Laravel's validation response — the credentials were rejected.
        // Anything else is a transport/server problem and deserves a different message.
        errorMessage.value = e.response?.status === 422 ? t('login.failed') : t('login.generic_error');
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-50 p-4 dark:bg-slate-950">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-cyan-700 text-white">
                    <UIcon name="i-lucide-footprints" class="size-7" />
                </div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-white">
                    {{ t('app.name') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ t('login.intro') }}
                </p>
            </div>

            <form
                class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                @submit.prevent="submit"
            >
                <UAlert
                    v-if="errorMessage"
                    color="error"
                    variant="soft"
                    icon="i-lucide-alert-circle"
                    :description="errorMessage"
                />

                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        {{ t('login.email') }}
                    </label>
                    <UInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        size="lg"
                        class="w-full"
                    />
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        {{ t('login.password') }}
                    </label>
                    <UInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        size="lg"
                        class="w-full"
                    />
                </div>

                <UButton
                    type="submit"
                    size="lg"
                    block
                    :loading="auth.loading"
                    :label="t('login.submit')"
                />
            </form>

            <div class="mt-6 flex justify-center gap-2">
                <button
                    v-for="l in SUPPORTED_LOCALES"
                    :key="l.code"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                        locale === l.code
                            ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                            : 'text-slate-600 hover:bg-slate-200 dark:text-slate-400 dark:hover:bg-slate-800'
                    "
                    :aria-pressed="locale === l.code"
                    @click="applyLocale(l.code)"
                >
                    {{ l.name }}
                </button>
            </div>
        </div>
    </div>
</template>
