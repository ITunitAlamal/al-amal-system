// A later selection always wins, even when a server finishes an aborted request.
export function createLatestRequest(load, publish) {
    let revision = 0;
    let controller;
    return {
        async run(url) {
            const current = ++revision;
            controller?.abort();
            controller = new AbortController();
            publish({ data: null, loading: Boolean(url), error: '' });
            if (!url) return;
            try {
                const data = await load(url, controller.signal);
                if (current === revision) publish({ data, loading: false, error: '' });
            } catch (error) {
                if (current === revision) publish({ data: null, loading: false, error: error.message || 'تعذر تحميل البيانات. أعد المحاولة.' });
            }
        },
        cancel() { revision++; controller?.abort(); },
    };
}
