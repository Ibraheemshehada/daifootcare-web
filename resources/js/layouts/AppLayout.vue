<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/stores/auth';
import { SUPPORTED_LOCALES, applyLocale } from '@/i18n';

const { t, locale } = useI18n();
const auth = useAuthStore();
const router = useRouter();

const sidebarOpen = ref(false);

const links = [
    { name: 'dashboard', label: 'nav.dashboard', icon: 'i-lucide-layout-dashboard' },
    { name: 'patients', label: 'nav.patients', icon: 'i-lucide-users' },
    { name: 'devices', label: 'nav.devices', icon: 'i-lucide-smartphone' },
    { name: 'scans', label: 'nav.scans', icon: 'i-lucide-activity' },
];

function switchLocale(code) {
    applyLocale(code);
}

async function signOut() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
        <!-- Sidebar -->
        <!--
            The off-canvas transform is scoped to `max-lg:` on purpose. Without it,
            `ltr:-translate-x-full` and `lg:translate-x-0` both apply at desktop widths
            and the direction variant wins the cascade, pushing the whole sidebar off
            screen while `lg:ps-64` still reserves its space — nav becomes unreachable.
        -->
        <aside
            class="fixed inset-y-0 start-0 z-40 w-64 border-e border-slate-200 bg-white transition-transform dark:border-slate-800 dark:bg-slate-900"
            :class="sidebarOpen ? 'translate-x-0' : 'max-lg:ltr:-translate-x-full max-lg:rtl:translate-x-full'"
        >
            <div class="flex h-16 items-center gap-3 border-b border-slate-200 px-5 dark:border-slate-800">
                <div class="flex size-9 items-center justify-center rounded-xl bg-cyan-700 text-white">
                    <UIcon name="i-lucide-footprints" class="size-5" />
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">
                        {{ t('app.name') }}
                    </p>
                    <p class="truncate text-xs text-slate-600 dark:text-slate-400">
                        {{ t('app.subtitle') }}
                    </p>
                </div>
            </div>

            <nav class="space-y-1 p-3" aria-label="Main">
                <RouterLink
                    v-for="link in links"
                    :key="link.name"
                    :to="{ name: link.name }"
                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                    active-class="bg-cyan-50 text-cyan-800 dark:bg-cyan-950 dark:text-cyan-200"
                    @click="sidebarOpen = false"
                >
                    <UIcon :name="link.icon" class="size-5 shrink-0" />
                    {{ t(link.label) }}
                </RouterLink>
            </nav>

            <div class="absolute inset-x-0 bottom-0 border-t border-slate-200 p-3 dark:border-slate-800">
                <div class="mb-2 flex gap-1" role="group" :aria-label="t('nav.language')">
                    <button
                        v-for="l in SUPPORTED_LOCALES"
                        :key="l.code"
                        type="button"
                        class="flex-1 rounded-md px-2 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            locale === l.code
                                ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
                        "
                        :aria-pressed="locale === l.code"
                        @click="switchLocale(l.code)"
                    >
                        {{ l.name }}
                    </button>
                </div>

                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                    @click="signOut"
                >
                    <UIcon name="i-lucide-log-out" class="size-5 shrink-0 rtl-flip" />
                    {{ t('nav.logout') }}
                </button>
            </div>
        </aside>

        <!-- Backdrop for mobile -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Main column -->
        <div class="lg:ps-64">
            <header
                class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/80 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80 sm:px-6"
            >
                <UButton
                    icon="i-lucide-menu"
                    color="neutral"
                    variant="ghost"
                    class="lg:hidden"
                    aria-label="Open navigation"
                    @click="sidebarOpen = true"
                />

                <div class="ms-auto flex items-center gap-3">
                    <span class="hidden text-sm text-slate-600 dark:text-slate-300 sm:block">
                        {{ auth.user?.name }}
                    </span>
                    <div
                        class="flex size-9 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200"
                    >
                        {{ (auth.user?.name ?? '?').charAt(0).toUpperCase() }}
                    </div>
                </div>
            </header>

            <main class="p-4 sm:p-6 lg:p-8">
                <RouterView v-slot="{ Component }">
                    <Transition name="fade" mode="out-in">
                        <component :is="Component" />
                    </Transition>
                </RouterView>
            </main>
        </div>
    </div>
</template>
