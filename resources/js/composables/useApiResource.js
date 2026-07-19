import { ref, onMounted } from 'vue';
import api from '@/lib/api';

/**
 * Loads a GET endpoint and exposes the three states every page here renders:
 * loading (skeleton), error (retry), and data.
 *
 * Centralised so no page can accidentally ship a bare "Loading..." string —
 * every consumer gets the same contract and renders a real skeleton.
 */
export function useApiResource(url, { params = {}, immediate = true } = {}) {
    const data = ref(null);
    const loading = ref(false);
    const error = ref(null);

    // The params in force right now. Merged rather than replaced so paging to
    // page 2 keeps an active search or filter — replacing them would silently
    // widen the result set the moment a user pages through a filtered list.
    let active = { ...params };

    async function load(overrideParams = {}) {
        loading.value = true;
        error.value = null;
        active = { ...active, ...overrideParams };

        try {
            const response = await api.get(url, { params: active });
            data.value = response.data;
            return response.data;
        } catch (e) {
            // 401 is handled globally by the interceptor; anything else surfaces here.
            error.value = e;
            return null;
        } finally {
            loading.value = false;
        }
    }

    if (immediate) {
        onMounted(() => load());
    }

    /** Reset filters back to the defaults, e.g. when clearing a search. */
    function reset(next = {}) {
        active = { ...params, ...next };
        return load();
    }

    return { data, loading, error, load, reset };
}
