import { ref, watch, onBeforeUnmount } from 'vue';
import api from '@/lib/api';

/**
 * Loads an image that lives behind `auth:sanctum`, and exposes it as a blob
 * URL an <img> tag can point at.
 *
 * A plain `<img :src="'/api/v1/...'">` cannot carry the Bearer token this API
 * requires — the browser issues that request itself, with no custom headers,
 * so it reaches the server unauthenticated no matter who is signed in on the
 * page. The request has to go through `api` (which attaches the token) and
 * come back as a blob; this wraps that so a template can still just bind an
 * `src`.
 *
 * `urlRef` may be a ref/computed, and may hold `null` for "nothing to show
 * yet" (e.g. before a row's scan is known) — the fetch simply skips.
 */
export function useAuthedImage(urlRef) {
    const src = ref(null);
    const loading = ref(false);
    const error = ref(null);

    let currentObjectUrl = null;

    function releaseCurrent() {
        if (currentObjectUrl) {
            URL.revokeObjectURL(currentObjectUrl);
            currentObjectUrl = null;
        }
    }

    async function fetchFor(url) {
        releaseCurrent();
        src.value = null;
        error.value = null;

        if (!url) return;

        loading.value = true;
        try {
            const response = await api.get(url, { responseType: 'blob' });
            currentObjectUrl = URL.createObjectURL(response.data);
            src.value = currentObjectUrl;
        } catch (e) {
            // 401 is handled globally by the interceptor; anything else (404,
            // the file missing on disk) just leaves src null, which the
            // template already treats as "no photo".
            error.value = e;
        } finally {
            loading.value = false;
        }
    }

    watch(urlRef, (url) => fetchFor(url), { immediate: true });

    // Object URLs are not garbage-collected on their own; they must be
    // revoked explicitly or the blob they point at leaks for the life of the
    // tab, one scan photo at a time.
    onBeforeUnmount(releaseCurrent);

    return { src, loading, error };
}
