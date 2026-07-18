import axios from 'axios';

const api = axios.create({
    baseURL: '/api/v1',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

/**
 * Attach the Sanctum token to every request.
 *
 * Read from the store lazily inside the interceptor rather than captured once at
 * module load — otherwise a login that happens after this file is imported would
 * keep sending the old (absent) token.
 */
api.interceptors.request.use((config) => {
    const token = localStorage.getItem('dfc_token');

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

/**
 * A 401 means the token is gone or revoked. Clear it and send the user to login
 * rather than letting every subsequent page render an empty error state.
 */
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('dfc_token');

            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }

        return Promise.reject(error);
    }
);

export default api;
