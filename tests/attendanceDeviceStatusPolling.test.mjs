import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createDeviceStatusPoller } from '../resources/js/attendance/deviceStatusPolling.js';

function createClock(fetcher, options = {}) {
    let now = 0;
    let nextId = 0;
    const timers = new Map();
    const poller = createDeviceStatusPoller({
        url: '/admin/attendance/devices/status',
        fetcher,
        intervalMs: 2000,
        maxBackoffMs: 8000,
        requestTimeoutMs: 8000,
        onError: options.onError,
        onStatus: options.onStatus,
        setTimer(callback, delay) {
            const id = ++nextId;
            timers.set(id, { callback, due: now + delay });

            return id;
        },
        clearTimer(id) {
            timers.delete(id);
        },
    });

    return {
        poller,
        timers,
        async advance(milliseconds) {
            const target = now + milliseconds;
            while (true) {
                const next = [...timers.entries()]
                    .filter(([, timer]) => timer.due <= target)
                    .sort((left, right) => left[1].due - right[1].due)[0];
                if (!next) {
                    break;
                }
                now = next[1].due;
                timers.delete(next[0]);
                next[1].callback();
                await new Promise(resolve => setImmediate(resolve));
            }
            now = target;
            await new Promise(resolve => setImmediate(resolve));
        },
    };
}

const response = devices => ({
    ok: true,
    json: async () => ({ devices }),
});

test('polls cached status immediately and then every two seconds without cache or overlapping requests', async () => {
    let calls = 0;
    const clock = createClock(async (url, options) => {
        calls++;
        assert.equal(url, '/admin/attendance/devices/status');
        assert.equal(options.cache, 'no-store');
        assert.equal(options.credentials, 'same-origin');

        return response([{ id: 1, device_state: 'connected' }]);
    });
    clock.poller.setActive(true);
    clock.poller.setActive(true);
    await clock.advance(0);
    assert.equal(calls, 1);
    await clock.advance(1999);
    assert.equal(calls, 1);
    await clock.advance(1);
    assert.equal(calls, 2);
    clock.poller.dispose();
});

test('backs off after network failure and resumes the normal interval after recovery', async () => {
    let calls = 0;
    let failures = 0;
    let recoveredDeviceState = null;
    const clock = createClock(async () => {
        calls++;
        return calls === 1 ? { ok: false, status: 503 } : response([{ id: 3, device_state: 'connected' }]);
    }, {
        onError: () => failures++,
        onStatus: devices => { recoveredDeviceState = devices[0].device_state; },
    });
    clock.poller.setActive(true);
    await clock.advance(0);
    assert.equal(failures, 1);
    assert.equal(calls, 1);
    await clock.advance(1999);
    assert.equal(calls, 1);
    await clock.advance(1);
    assert.equal(calls, 2);
    assert.equal(recoveredDeviceState, 'connected');
    clock.poller.dispose();
});

test('times out a slow request, reports the failure, and schedules a backed-off retry', async () => {
    let failures = 0;
    const clock = createClock((url, { signal }) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(new Error('aborted')));
    }), { onError: () => failures++ });
    clock.poller.setActive(true);
    await clock.advance(0);
    await clock.advance(7999);
    assert.equal(failures, 0);
    await clock.advance(1);
    assert.equal(failures, 1);
    assert.equal(clock.timers.size, 1);
    clock.poller.dispose();
});

test('does not send another request while the previous cached-status read is pending', async () => {
    let calls = 0;
    let resolveRequest;
    const clock = createClock(() => {
        calls++;

        return new Promise(resolve => {
            resolveRequest = resolve;
        });
    });
    clock.poller.setActive(true);
    await clock.advance(0);
    await clock.advance(5000);
    assert.equal(calls, 1);
    resolveRequest(response([{ id: 1, device_state: 'connected' }]));
    await new Promise(resolve => setImmediate(resolve));
    await clock.advance(2000);
    assert.equal(calls, 2);
    clock.poller.dispose();
});

test('pauses while hidden or inactive, aborts pending requests, resumes, and cleans up on unmount', async () => {
    let calls = 0;
    const clock = createClock((url, { signal }) => {
        calls++;

        return new Promise((resolve, reject) => {
            signal.addEventListener('abort', () => reject(new Error('aborted')));
        });
    });
    clock.poller.setActive(true);
    await clock.advance(0);
    clock.poller.setVisible(false);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(clock.timers.size, 0);
    clock.poller.setVisible(true);
    await clock.advance(0);
    assert.equal(calls, 2);
    clock.poller.setActive(false);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(clock.timers.size, 0);
    clock.poller.dispose();
    assert.equal(clock.timers.size, 0);
});

test('separate browser tabs maintain independent polling without duplicate timers per tab', async () => {
    let calls = 0;
    const tabs = [createClock(async () => {
        calls++;

        return response([{ id: 1, device_state: 'unknown' }]);
    }), createClock(async () => {
        calls++;

        return response([{ id: 1, device_state: 'unknown' }]);
    })];
    tabs.forEach(tab => tab.poller.setActive(true));
    await Promise.all(tabs.map(tab => tab.advance(0)));
    assert.equal(calls, 2);
    assert.equal(tabs.every(tab => tab.timers.size === 1), true);
    tabs.forEach(tab => tab.poller.dispose());
});
