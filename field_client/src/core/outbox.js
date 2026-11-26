'use strict';

// Append-only encrypted outbox of delivery events. Each event is the exact JSON text the device signs, chained to the
// previous one by hash, stored as its own sealed record before the user sees "recorded". Records are never deleted
// before the server acknowledges them; a crash between writes leaves either the old or the new record (atomic rename).
const crypto = require('crypto');

const PREFIX = 'outbox-';

class Outbox {
    constructor(vault) {
        this.vault = vault;
    }

    entries() {
        return this.vault.list(PREFIX).map(name => ({ name, ...this.vault.read(name) }));
    }

    lastHash() {
        const all = this.entries();
        return all.length ? all[all.length - 1].hash : null;
    }

    /**
     * @param {object} payload unsigned event body (event_uuid set by the caller)
     * @param {(text: string) => string} sign device signature function
     * @param {string|null} signatureImage base64 PNG of the recipient's handwritten signature (sealed with the event)
     */
    append(payload, sign, signatureImage) {
        const sequence = String(this.vault.list(PREFIX).length + 1).padStart(8, '0');
        const body = { ...payload, prev_hash: this.lastHash() };
        const text = JSON.stringify(body);
        const record = {
            sequence, uuid: body.event_uuid, payload: text, signature: sign(text), signature_image: signatureImage,
            hash: crypto.createHash('sha256').update(text, 'utf8').digest('hex'), state: 'LocallyRecordedPendingSync', recordedAt: new Date().toISOString(), ack: null,
        };
        this.vault.write(`${PREFIX}${sequence}-${body.event_uuid}`, record);
        return record;
    }

    pending() {
        return this.entries().filter(e => e.state === 'LocallyRecordedPendingSync' || e.state === 'UploadedPendingValidation');
    }

    /** Stores the server acknowledgement on the event (Confirmed / ConflictHeld / Rejected); the evidence stays. */
    acknowledge(uuid, ack) {
        const entry = this.entries().find(e => e.uuid === uuid);
        if (!entry) return;
        const state = { confirmed: 'ConfirmedAndReconciled', conflict_held: 'ConflictHeld', rejected: 'RejectedWithReason' }[ack.status] || 'UploadedPendingValidation';
        const { name, ...record } = entry;
        // Once acknowledged, the signature image is no longer needed on the device: the server holds the evidence.
        this.vault.write(name, { ...record, state, ack, signature_image: state === 'UploadedPendingValidation' ? record.signature_image : null });
    }

    markUploading(uuids) {
        for (const entry of this.entries().filter(e => uuids.includes(e.uuid) && e.state === 'LocallyRecordedPendingSync')) {
            const { name, ...record } = entry;
            this.vault.write(name, { ...record, state: 'UploadedPendingValidation' });
        }
    }
}

module.exports = { Outbox };
