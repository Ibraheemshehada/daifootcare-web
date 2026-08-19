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
        path: '/patients/:id',
        name: 'patient-detail',
        component: () => import('@/pages/PatientDetailPage.vue'),
        meta: { requiresAuth: true, title: 'patient.title' },
    },
    {
        path: '/study',
        name: 'study',
        component: () => import('@/pages/StudyPage.vue'),
        meta: { requiresAuth: true, title: 'study.title' },
    },
    {
        path: '/scans',
        name: 'scans',
        component: () => import('@/pages/ScansPage.vue'),
        meta: { requiresAuth: true, title: 'scans.title' },
    },
    {
        path: '/alerts',
        name: 'alerts',
        component: () => import('@/pages/AlertsPage.vue'),
        meta: { requiresAuth: true, title: 'alerts.title' },
    },
    {
        path: '/appointments',
        name: 'appointments',
        component: () => import('@/pages/AppointmentsPage.vue'),
        meta: { requiresAuth: true, title: 'appointments.title' },
    },
    {
        path: '/medications',
        name: 'medications',
        component: () => import('@/pages/MedicationsPage.vue'),
        meta: { requiresAuth: true, title: 'medications.title' },
    },
    {
        path: '/devices/:uuid',
        name: 'device-detail',
        component: () => import('@/pages/DeviceDetailPage.vue'),
        meta: { requiresAuth: true, title: 'devices.detail_title' },
    },
    {
        path: '/sync-monitor',
        name: 'sync-monitor',
        component: () => import('@/pages/SyncMonitorPage.vue'),
        meta: { requiresAuth: true, title: 'sync.title' },
    },
    {
        // A bench for looking at what the models say about one photograph.
        // Nothing it does is recorded, so it never appears in a patient's
        // history or in the study's numbers.
        path: '/analysis-probe',
        name: 'analysis-probe',
        component: () => import('@/pages/AnalysisProbePage.vue'),
        meta: { requiresAuth: true, adminOnly: true, title: 'probe.title' },
    },
    {
        path: '/users',
        name: 'users',
        component: () => import('@/pages/UsersPage.vue'),
        meta: { requiresAuth: true, adminOnly: true, title: 'users.title' },
    },
    {
        path: '/export',
        name: 'export',
        component: () => import('@/pages/ExportPage.vue'),
        meta: { requiresAuth: true, title: 'export.title' },
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
    //
    // This must run BEFORE any role check: on a full page load (a typed URL or a
    // refresh) auth.user is still null, so a role check placed above it would
    // short-circuit and wave the request straight through.
    if (auth.isAuthenticated && !auth.user) {
        try {
            await auth.fetchUser();
        } catch {
            // The 401 interceptor already cleared the token and redirected.
            return { name: 'login' };
        }
    }

    // The API enforces this too; this only avoids landing someone on a page
    // where every request 403s.
    if (to.meta.adminOnly && auth.user?.role !== 'admin') {
        return { name: 'dashboard' };
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

/**
 * Recover from a deploy that happened while someone had the page open.
 *
 * Vite empties the build directory, so each deploy deletes the previous
 * chunks. A browser holding a cached index.html then asks for a hash that no
 * longer exists, the dynamic import rejects, and the route never renders — the
 * already-loaded shell stays on screen with an empty content area, which looks
 * exactly like a broken or unauthorised dashboard. This was reported by real
 * users after a series of deploys.
 *
 * Reloading fetches fresh HTML with the current hashes and lands them on the
 * page they asked for. The sessionStorage flag stops a genuinely missing chunk
 * from becoming a reload loop.
 */
router.onError((error, to) => {
    const looksLikeStaleChunk = /dynamically imported module|Importing a module script failed|Failed to fetch/i
        .test(error?.message ?? '');

    if (!looksLikeStaleChunk) return;

    const KEY = 'dfc_chunk_reload';
    if (sessionStorage.getItem(KEY)) {
        sessionStorage.removeItem(KEY);
        return; // already tried; let the error surface rather than loop
    }

    sessionStorage.setItem(KEY, '1');
    window.location.assign(to?.fullPath ?? window.location.pathname);
});

// A successful navigation means whatever we reloaded for is resolved.
router.afterEach(() => sessionStorage.removeItem('dfc_chunk_reload'));

router.afterEach(applyTitle);

// The title also has to follow a language switch. `afterEach` alone only fires
// on navigation, so switching to Arabic while sitting on the landing page left
// an English title behind.
watch(
    () => i18n.global.locale.value,
    () => applyTitle(router.currentRoute.value)
);

export default router;
