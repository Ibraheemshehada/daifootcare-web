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

    async function load(overrideParams = {}) {
        loading.value = true;
        error.value = null;

        try {
            const response = await api.get(url, { params: { ...params, ...overrideParams } });
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

    return { data, loading, error, load };
}
