export function createDeviceStatusPoller({
    url,
    fetcher = globalThis.fetch,
    onStatus,
    onError,
    intervalMs = 2000,
    maxBackoffMs = 30000,
    requestTimeoutMs = 8000,
    setTimer = globalThis.setTimeout,
    clearTimer = globalThis.clearTimeout,
}) {
    let active = false;
    let visible = true;
    let disposed = false;
    let inFlight = false;
    let pollTimer = null;
    let requestTimeout = null;
    let controller = null;
    let failures = 0;
    let abortReason = null;

    function clearPollTimer() {
        if (pollTimer !== null) {
            clearTimer(pollTimer);
            pollTimer = null;
        }
    }

    function pauseRequest() {
        clearPollTimer();
        abortReason = 'paused';
        controller?.abort();
    }

    function schedule(delay) {
        clearPollTimer();
        if (!disposed && active && visible) {
            pollTimer = setTimer(() => {
                pollTimer = null;
                void poll();
            }, delay);
        }
    }

    async function poll() {
        if (disposed || !active || !visible || inFlight) {
            return;
        }

        inFlight = true;
        abortReason = null;
        controller = new AbortController();
        requestTimeout = setTimer(() => {
            abortReason = 'timeout';
            controller?.abort();
        }, requestTimeoutMs);
        let nextDelay = intervalMs;

        try {
            const response = await fetcher(url, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error(`status_${response.status}`);
            }

            const payload = await response.json();
            if (!Array.isArray(payload?.devices)) {
                throw new Error('invalid_status_payload');
            }

            failures = 0;
            if (!disposed && active && visible) {
                onStatus(payload.devices);
            }
        } catch (error) {
            if (!disposed && active && visible && abortReason !== 'paused') {
                failures++;
                onError?.(error);
                nextDelay = Math.min(intervalMs * (2 ** (failures - 1)), maxBackoffMs);
            }
        } finally {
            if (requestTimeout !== null) {
                clearTimer(requestTimeout);
                requestTimeout = null;
            }
            controller = null;
            inFlight = false;
            if (!disposed && active && visible) {
                schedule(nextDelay);
            }
        }
    }

    return {
        setActive(value) {
            active = Boolean(value);
            if (active && visible) {
                schedule(0);
            } else {
                pauseRequest();
            }
        },
        setVisible(value) {
            visible = Boolean(value);
            if (visible && active) {
                schedule(0);
            } else {
                pauseRequest();
            }
        },
        dispose() {
            disposed = true;
            active = false;
            pauseRequest();
        },
    };
}
