import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import api from '@/lib/api';

const TOKEN_KEY = 'dfc_token';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const token = ref(null);
    const loading = ref(false);

    const isAuthenticated = computed(() => !!token.value);
    const isClinician = computed(() => ['admin', 'doctor'].includes(user.value?.role));

    function restore() {
        token.value = localStorage.getItem(TOKEN_KEY);
    }

    function setToken(value) {
        token.value = value;
        if (value) {
            localStorage.setItem(TOKEN_KEY, value);
        } else {
            localStorage.removeItem(TOKEN_KEY);
        }
    }

    async function login(credentials) {
        loading.value = true;
        try {
            const { data } = await api.post('/auth/login', credentials);
            setToken(data.token);
            user.value = data.user;
            return data.user;
        } finally {
            loading.value = false;
        }
    }

    /** Fetch the current user for a token restored from storage. */
    async function fetchUser() {
        if (!token.value) return null;

        const { data } = await api.get('/auth/me');
        user.value = data.user;
        return data.user;
    }

    async function logout() {
        try {
            await api.post('/auth/logout');
        } catch {
            // The token may already be revoked or the network may be down. Either
            // way the local session must still be cleared, so this is non-fatal.
        } finally {
            setToken(null);
            user.value = null;
        }
    }

    return { user, token, loading, isAuthenticated, isClinician, restore, login, fetchUser, logout };
});
