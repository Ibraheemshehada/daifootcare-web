<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, RouterLink } from 'vue-router';

/**
 * The public support and privacy pages.
 *
 * One component for both: they are the same shape — a title, a date, and a list
 * of headed sections — and the only thing that differs is which key the text
 * comes from. Two files would drift.
 *
 * These exist because the App Store requires a working support URL and a
 * privacy policy URL, and neither existed. `/support` and `/privacy` returned
 * HTTP 200 while rendering the not-found page, which is worse than a 404: it
 * looks reachable to anything that only checks the status code, and reads as a
 * dead link to the reviewer who opens it.
 *
 * The content lives in the locale files so it stays translatable, and the
 * sections are an array so adding one is an edit to text rather than to markup.
 */
const { t, tm, rt } = useI18n();
const route = useRoute();

const page = computed(() => (route.name === 'privacy' ? 'privacy' : 'support'));

// tm() returns the raw message array; rt() resolves each entry. Without rt the
// strings render as vue-i18n AST objects rather than text.
const sections = computed(() =>
    (tm(`${page.value}.sections`) ?? []).map((s) => ({
        heading: rt(s.heading),
        body: (s.body ?? []).map((line) => rt(line)),
    })),
);
</script>

<template>
    <div class="min-h-screen bg-white dark:bg-slate-950">
        <header class="border-b border-slate-200 dark:border-slate-800">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-5 py-4">
                <RouterLink :to="{ name: 'landing' }"
                            class="flex items-center gap-3 text-slate-900 dark:text-white">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-cyan-700 text-white">
                        <UIcon name="i-lucide-footprints" class="size-5" />
                    </span>
                    <span class="text-sm font-semibold">{{ t('app.name') }}</span>
                </RouterLink>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-5 py-10">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">
                {{ t(`${page}.title`) }}
            </h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ t(`${page}.updated`) }}
            </p>
            <p class="mt-6 text-base leading-relaxed text-slate-700 dark:text-slate-300">
                {{ t(`${page}.intro`) }}
            </p>

            <section v-for="(s, i) in sections" :key="i" class="mt-8">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                    {{ s.heading }}
                </h2>
                <p v-for="(line, j) in s.body" :key="j"
                   class="mt-3 text-base leading-relaxed text-slate-700 dark:text-slate-300">
                    {{ line }}
                </p>
            </section>

            <!-- The contact address is the whole point of the support page, and
                 the reviewer will click it. Given its own block so it cannot be
                 lost in a paragraph. -->
            <section class="mt-10 rounded-xl border border-slate-200 bg-slate-50 p-5
                            dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                    {{ t('legal.contact_heading') }}
                </h2>
                <p class="mt-2 text-base text-slate-700 dark:text-slate-300">
                    {{ t('legal.contact_body') }}
                </p>
                <a class="mt-3 inline-block text-base font-medium text-cyan-700 underline dark:text-cyan-400"
                   :href="`mailto:${t('legal.contact_email')}`">
                    {{ t('legal.contact_email') }}
                </a>
            </section>

            <nav class="mt-10 flex flex-wrap gap-x-6 gap-y-2 border-t border-slate-200 pt-6
                        text-sm dark:border-slate-800">
                <RouterLink :to="{ name: 'landing' }"
                            class="text-slate-600 hover:underline dark:text-slate-400">
                    {{ t('legal.nav_home') }}
                </RouterLink>
                <RouterLink :to="{ name: 'support' }"
                            class="text-slate-600 hover:underline dark:text-slate-400">
                    {{ t('support.title') }}
                </RouterLink>
                <RouterLink :to="{ name: 'privacy' }"
                            class="text-slate-600 hover:underline dark:text-slate-400">
                    {{ t('privacy.title') }}
                </RouterLink>
            </nav>
        </main>
    </div>
</template>
