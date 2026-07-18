import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { guestOnly: true, layout: 'blank' },
    },
    {
        path: '/',
        redirect: { name: 'dashboard' },
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/devices',
        name: 'devices',
        component: () => import('@/pages/DevicesPage.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/patients',
        name: 'patients',
        component: () => import('@/pages/PatientsPage.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/scans',
        name: 'scans',
        component: () => import('@/pages/ScansPage.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { layout: 'blank' },
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

export default router;
