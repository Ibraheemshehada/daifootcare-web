import { watch } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import i18n from '@/i18n';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { guestOnly: true, layout: 'blank', title: 'login.title' },
    },
    {
        // Public marketing page. Deliberately not redirected to the dashboard for
        // signed-in staff — '/' is the product's public face, and a clinician who
        // types the bare domain should still be able to reach it.
        path: '/',
        name: 'landing',
        component: () => import('@/pages/LandingPage.vue'),
        meta: { layout: 'blank', title: 'landing.meta_title' },
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { requiresAuth: true, title: 'dashboard.title' },
    },
    {
        path: '/devices',
        name: 'devices',
        component: () => import('@/pages/DevicesPage.vue'),
        meta: { requiresAuth: true, title: 'devices.title' },
    },
    {
        path: '/patients',
        name: 'patients',
        component: () => import('@/pages/PatientsPage.vue'),
        meta: { requiresAuth: true, title: 'patients.title' },
    },
    {
        path: '/scans',
        name: 'scans',
        component: () => import('@/pages/ScansPage.vue'),
        meta: { requiresAuth: true, title: 'scans.title' },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { layout: 'blank', title: 'notfound.title' },
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'dashboard' };
    }

    // A restored token has no user attached yet. Resolve it once, here, so pages
    // can rely on auth.user being populated instead of each guarding for null.
    if (auth.isAuthenticated && !auth.user) {
        try {
            await auth.fetchUser();
        } catch {
            // The 401 interceptor already cleared the token and redirected.
            return { name: 'login' };
        }
    }

    return true;
});

/**
 * Per-route document title.
 *
 * Without this every page inherits the Blade shell's title, so the public
 * landing page announces itself as "Clinical Dashboard" — wrong for the one
 * page that search engines and first-time visitors actually see.
 */
function applyTitle(route) {
    const key = route?.meta?.title;
    const name = i18n.global.t('app.name');

    document.title = key ? `${i18n.global.t(key)} — ${name}` : name;
}

router.afterEach(applyTitle);

// The title also has to follow a language switch. `afterEach` alone only fires
// on navigation, so switching to Arabic while sitting on the landing page left
// an English title behind.
watch(
    () => i18n.global.locale.value,
    () => applyTitle(router.currentRoute.value)
);

export default router;
