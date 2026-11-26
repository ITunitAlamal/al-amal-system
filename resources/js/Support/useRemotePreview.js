import { onUnmounted, ref, watch } from 'vue';
import { createLatestRequest } from './latestRequest.js';
import { jsonRequest } from './orphans.js';

export function useRemotePreview(url) {
    const data = ref(null);
    const failure = ref('');
    const loading = ref(false);
    const request = createLatestRequest((path, signal) => jsonRequest(path, null, 'GET', { signal }), state => {
        data.value = state.data;
        failure.value = state.error;
        loading.value = state.loading;
    });
    const retry = () => request.run(url());
    watch(url, retry, { immediate: true });
    onUnmounted(() => request.cancel());
    return { data, failure, loading, retry };
}
