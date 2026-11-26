'use strict';

// Offline authorization of one downloaded manifest: at most 168 hours from its server issue time, never extended by
// reopening the app or by moving the device clock. Elapsed time is the larger of (a) the wall clock corrected by the
// server time seen at download and (b) a running total accumulated from the OS uptime counter (monotonic within a
// boot) and from forward wall-clock steps across reboots. A clock moved backwards is recorded as a tamper signal and
// cannot reduce the elapsed time. State is kept in the authenticated vault, so it cannot be edited on disk.
// Residual risk (documented): on an unmanaged device switched off and with its clock set back before rebooting, the
// time spent powered off can be under-counted; revocation still reaches the device at its next network contact.
const os = require('os');

const TICK_MS = 30_000;
const STEP_TOLERANCE_MS = 5 * 60_000;

class OfflineClock {
    constructor(vault, name, now = () => Date.now(), uptime = () => os.uptime()) {
        this.vault = vault;
        this.name = name;
        this.now = now;
        this.uptime = uptime;
    }

    /** Starts the window for a freshly downloaded manifest. */
    start(issuedAtIso, expiresAtIso, serverTimeIso) {
        const now = this.now();
        const state = {
            issuedAt: Date.parse(issuedAtIso), expiresAt: Date.parse(expiresAtIso),
            skew: Date.parse(serverTimeIso) - now, accumulated: 0, lastWall: now, lastUptime: this.uptime(), tamper: [],
        };
        if (!Number.isFinite(state.issuedAt) || !Number.isFinite(state.expiresAt) || state.expiresAt <= state.issuedAt
            || state.expiresAt - state.issuedAt > 168 * 3600_000) {
            throw new Error('نافذة العمل دون اتصال غير صالحة.');
        }
        this.vault.write(this.name, state);
        return this.status();
    }

    /** Updates the running totals from the time observed since the last tick and persists them. */
    tick() {
        const state = this.vault.read(this.name);
        if (!state) return null;
        const now = this.now();
        const up = this.uptime();
        const wallStep = now - state.lastWall;
        if (up >= state.lastUptime) {
            const monotonic = (up - state.lastUptime) * 1000;
            state.accumulated += monotonic;
            if (wallStep < monotonic - STEP_TOLERANCE_MS) state.tamper.push({ at: now, kind: 'clock_back', ms: monotonic - wallStep });
        } else {
            // A reboot happened: only a forward wall-clock step can be counted for the time switched off.
            if (wallStep > 0) state.accumulated += wallStep;
            else state.tamper.push({ at: now, kind: 'clock_back_across_reboot', ms: -wallStep });
        }
        state.lastWall = now;
        state.lastUptime = up;
        state.tamper = state.tamper.slice(-20);
        this.vault.write(this.name, state);
        return state;
    }

    status() {
        const state = this.tick();
        if (!state) return { active: false, expired: true, remainingMs: 0, elapsedMs: 0, tamper: [] };
        // The clock that says more time has passed wins; neither a backwards clock nor a restart can shorten it.
        const wallElapsed = this.now() + state.skew - state.issuedAt;
        const elapsed = Math.max(state.accumulated, wallElapsed);
        const window = state.expiresAt - state.issuedAt;
        return { active: true, expired: elapsed >= window, remainingMs: Math.max(0, window - elapsed), elapsedMs: elapsed, tamper: state.tamper,
            expiresAt: new Date(state.expiresAt).toISOString(), serverNowMs: this.now() + state.skew };
    }

    clear() {
        this.vault.remove(this.name);
    }
}

module.exports = { OfflineClock, TICK_MS };
