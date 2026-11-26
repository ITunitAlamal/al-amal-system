import { onMounted, onUnmounted, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const allowed = {
    '/admin/employees': ['q', 'status', 'page'],
    '/admin/employee-profiles': ['search', 'department', 'state', 'page'],
    '/correspondence': ['q', 'box', 'page'],
    '/orphans/registry': ['q', 'view', 'layout', 'page', 'sort', 'first_name', 'father_name', 'grandfather_name', 'family_name', 'governorate', 'locality', 'area', 'sex', 'age_band', 'class', 'status', 'completeness', 'guardianship', 'displaced', 'education', 'issue', 'linked', 'portal', 'sponsorship', 'benefit', 'assigned'],
    '/orphans/reviews': ['q', 'tab', 'status', 'assigned', 'page'],
};

export function useListFilters() {
    const page = usePage();
    const remembered = new Map();
    let owner;
    let stopBefore;
    let stopWatch;

    onMounted(() => {
        stopWatch = watch(() => [page.url, page.props.auth?.user?.id], ([url, userId]) => {
            if (owner !== userId) {
                remembered.clear();
                owner = userId;
            }
            if (!userId || !url) return;
            const current = new URL(url, window.location.origin);
            const keys = allowed[current.pathname];
            if (!keys) return;
            const query = new URLSearchParams();
            for (const key of keys) {
                if (current.searchParams.has(key)) query.set(key, current.searchParams.get(key));
            }
            remembered.set(current.pathname, query.toString());
        }, { immediate: true });

        stopBefore = router.on('before', event => {
            const visit = event.detail.visit;
            if (event.defaultPrevented || visit.method !== 'get' || visit.prefetch || visit.preserveState || visit.showProgress === false) return;
            if (visit.url.origin !== window.location.origin || visit.url.search) return;
            const query = remembered.get(visit.url.pathname);
            if (query) visit.url.search = query;
        });
    });
    onUnmounted(() => {
        stopWatch?.();
        stopBefore?.();
        remembered.clear();
    });
}
