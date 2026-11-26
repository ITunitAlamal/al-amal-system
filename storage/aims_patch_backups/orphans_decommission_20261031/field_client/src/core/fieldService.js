'use strict';

// Field workflow on the device: pin the server key, sign in online with employee credentials and the device key,
// download signed batch manifests, work offline for at most the manifest's window, record deliveries (identity
// check, handwritten signature, "تم التسليم") into the encrypted outbox, and sync with idempotent acknowledgements.
// The distributor can never add a beneficiary or raise a quantity: everything is checked against the signed manifest.
const crypto = require('crypto');
const { Api } = require('./api');
const deviceKeys = require('./deviceKeys');
const { OfflineClock } = require('./offlineClock');
const { Outbox } = require('./outbox');

const MAX_PIN_ATTEMPTS = 5;

class FieldService {
    constructor(vault, meta = {}) {
        this.vault = vault;
        this.device = deviceKeys.loadOrCreate(vault);
        this.outbox = new Outbox(vault);
        this.meta = { name: meta.name || 'Windows device', platform: meta.platform || process.platform, version: meta.version || '1.0.0' };
        this.locked = true;
        this.api = null;
        const config = vault.read('config');
        if (config) this.useServer(config.serverUrl);
    }

    useServer(serverUrl) {
        this.api = new Api(serverUrl);
        this.api.fingerprint = this.device.fingerprint;
        const session = this.vault.read('session');
        if (session && Date.parse(session.expiresAt) > Date.now()) this.api.token = session.token;
    }

    /** First step: show the server key id so it can be compared with the one displayed in AIMS before pinning it. */
    async probeServer(serverUrl) {
        const api = new Api(serverUrl);
        const { data } = await api.server();
        return { keyId: data.key_id, publicKey: data.public_key, maxOfflineHours: data.max_offline_hours };
    }

    pinServer(serverUrl, publicKey, keyId) {
        if (this.vault.has('config') && this.outbox.pending().length) throw new Error('لا يُغيّر الخادم مع وجود أحداث غير مرفوعة.');
        const expectedId = crypto.createHash('sha256').update(Buffer.from(publicKey, 'base64')).digest('hex').slice(0, 16);
        if (expectedId !== keyId) throw new Error('بصمة مفتاح الخادم لا تطابق.');
        this.vault.write('config', { serverUrl, serverKey: publicKey, keyId, pinnedAt: new Date().toISOString() });
        this.useServer(serverUrl);
    }

    config() {
        return this.vault.read('config');
    }

    async signIn(employeeNumber, password) {
        this.requireServer();
        const signedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
        const { status, data } = await this.api.signIn({ employee_number: employeeNumber, password, public_key: this.device.publicKeyBase64, device_name: this.meta.name,
            platform: this.meta.platform, app_version: this.meta.version, signed_at: signedAt, proof: this.device.sign(`aims-field-login|${employeeNumber.trim()}|${signedAt}`) });
        if (status === 202 || data.status !== 'approved') {
            return { status: data.status || 'pending', fingerprint: this.device.fingerprint.slice(0, 16) };
        }
        this.vault.write('session', { token: data.token, expiresAt: data.expires_at, user: data.user, device: data.device, serverTime: data.server_time });
        this.api.token = data.token;
        this.locked = !this.vault.has('pin');
        return { status: 'approved', user: data.user, needsPin: !this.vault.has('pin') };
    }

    setPin(pin) {
        if (!/^\d{6,12}$/.test(pin)) throw new Error('الرمز من 6 إلى 12 رقمًا.');
        if (!this.vault.read('session')) throw new Error('سجّل الدخول متصلًا أولًا.');
        const salt = crypto.randomBytes(16);
        this.vault.write('pin', { salt: salt.toString('base64'), hash: crypto.scryptSync(pin, salt, 32).toString('base64'), attempts: 0 });
        this.locked = false;
    }

    unlock(pin) {
        const record = this.vault.read('pin');
        if (!record) throw new Error('لا يوجد رمز قفل؛ سجّل الدخول متصلًا.');
        const ok = crypto.timingSafeEqual(crypto.scryptSync(String(pin), Buffer.from(record.salt, 'base64'), 32), Buffer.from(record.hash, 'base64'));
        if (!ok) {
            const attempts = record.attempts + 1;
            if (attempts >= MAX_PIN_ATTEMPTS) {
                // Too many wrong codes: end the local session and drop downloaded beneficiary data; unsynced evidence stays.
                this.wipeSession();
                throw new Error('تجاوزت المحاولات المسموحة؛ أُنهيت الجلسة وحُذفت بيانات الدفعات المنزلة (الأحداث غير المرفوعة محفوظة). سجّل الدخول متصلًا.');
            }
            this.vault.write('pin', { ...record, attempts });
            throw new Error(`رمز غير صحيح (${attempts} من ${MAX_PIN_ATTEMPTS}).`);
        }
        this.vault.write('pin', { ...record, attempts: 0 });
        this.locked = false;
        return true;
    }

    lock() {
        this.locked = true;
    }

    status() {
        const session = this.vault.read('session');
        return {
            configured: Boolean(this.config()), keyId: this.config()?.keyId, fingerprint: this.device.fingerprint.slice(0, 16), locked: this.locked,
            session: session ? { user: session.user, expiresAt: session.expiresAt, online: Date.parse(session.expiresAt) > Date.now() } : null,
            hasPin: this.vault.has('pin'), pending: this.outbox.pending().length,
            outbox: this.outbox.entries().reduce((counts, e) => ({ ...counts, [e.state]: (counts[e.state] || 0) + 1 }), {}),
            manifests: this.manifestIds().map(id => this.batchSummary(id)),
        };
    }

    async listBatches() {
        this.requireUnlocked();
        const { data } = await this.api.batches();
        return data.batches;
    }

    async download(batchId) {
        this.requireUnlocked();
        const { data } = await this.api.manifest(batchId);
        const config = this.config();
        if (data.key_id !== config.keyId || !deviceKeys.verifyServer(data.manifest, data.signature, config.serverKey)) {
            throw new Error('توقيع بيان الدفعة غير صحيح؛ لم يُحفظ.');
        }
        const manifest = JSON.parse(data.manifest);
        if (manifest.device !== this.device.fingerprint || manifest.kind !== 'aims.field.manifest') throw new Error('البيان صادر لجهاز آخر.');
        this.vault.write(`manifest-${manifest.batch.id}`, { text: data.manifest, signature: data.signature, downloadedAt: new Date().toISOString() });
        new OfflineClock(this.vault, `clock-${manifest.batch.id}`).start(manifest.issued_at, manifest.expires_at, manifest.issued_at);
        return this.batchSummary(manifest.batch.id);
    }

    manifestIds() {
        return this.vault.list('manifest-').map(name => name.slice('manifest-'.length));
    }

    /** The manifest as stored, re-verified on every read (a sealed record that fails its signature is not used). */
    manifest(batchId) {
        const record = this.vault.read(`manifest-${batchId}`);
        if (!record) return null;
        if (!deviceKeys.verifyServer(record.text, record.signature, this.config().serverKey)) throw new Error('بيان الدفعة المخزن لم يعد صالحًا.');
        return JSON.parse(record.text);
    }

    batchSummary(batchId) {
        const manifest = this.manifest(batchId);
        const clock = new OfflineClock(this.vault, `clock-${batchId}`).status();
        const events = this.outbox.entries().map(e => ({ ...JSON.parse(e.payload), state: e.state, ack: e.ack })).filter(e => e.batch === batchId);
        return {
            id: batchId, reference: manifest.batch.reference, version: manifest.batch.version, campaign: manifest.campaign, issuedAt: manifest.issued_at, expiresAt: manifest.expires_at,
            clock, policy: manifest.policy, failureReasons: manifest.failure_reasons,
            entries: manifest.entries.map(entry => ({ ...entry, local: this.localState(entry, events.filter(e => e.allocation === entry.allocation)) })),
        };
    }

    localState(entry, events) {
        const delivered = {};
        for (const e of events.filter(x => ['delivered', 'partial'].includes(x.kind) && x.state !== 'RejectedWithReason')) {
            for (const [item, qty] of Object.entries(e.items || {})) delivered[item] = (delivered[item] || 0) + Number(qty);
        }
        const remaining = Object.fromEntries(entry.items.map(i => [i.id, Math.max(0, i.quantity - (delivered[i.id] || 0))]));
        const last = events[events.length - 1];
        return { remaining, complete: Object.values(remaining).every(q => q <= 0.0001), events: events.length, lastState: last?.state || null, lastKind: last?.kind || null };
    }

    /**
     * Records one outcome offline. Delivery needs: an unexpired window, the registered guardian (no unknown proxy),
     * matching last digits of the presented document, the distributor's attestation, quantities within what remains,
     * and the recipient's handwritten signature. Anything else is recorded as not delivered / referral, never as delivered.
     */
    record(batchId, allocationId, input) {
        this.requireUnlocked();
        const manifest = this.manifest(batchId);
        if (!manifest) throw new Error('الدفعة غير منزلة على هذا الجهاز.');
        const clock = new OfflineClock(this.vault, `clock-${batchId}`);
        const window = clock.status();
        if (window.expired) throw new Error('انتهت صلاحية العمل دون اتصال لهذه الدفعة؛ اتصل بالخادم وأعد تنزيلها. لا تُسجّل تسليمات جديدة.');
        const entry = manifest.entries.find(e => e.allocation === allocationId);
        if (!entry) throw new Error('المستفيد غير موجود في البيان؛ لا يجوز إضافة مستفيدين في الميدان.');
        const events = this.outbox.entries().map(e => ({ ...JSON.parse(e.payload), state: e.state })).filter(e => e.allocation === allocationId);
        const local = this.localState(entry, events);
        const kind = input.kind;
        const payload = { event_uuid: crypto.randomUUID(), batch: manifest.batch.id, allocation: allocationId, manifest_version: manifest.batch.version, kind,
            // Wall time corrected by the server time seen at download; the server compares it with the elapsed counter.
            occurred_at: new Date(window.serverNowMs).toISOString(), clock: { elapsed_ms: Math.round(window.elapsedMs), tamper: window.tamper.length },
            device_fingerprint: this.device.fingerprint };
        let image = null;
        if (kind === 'delivered' || kind === 'partial') {
            if (local.complete) throw new Error('سُلّم هذا المخصص كاملًا على هذا الجهاز.');
            if (input.relation !== 'guardian') throw new Error('المستلم ليس الوصي المسجل؛ سجّل «إحالة» للمراجعة.');
            if (String(input.idLast4 || '') !== String(entry.receiver.id_last4)) throw new Error('آخر أرقام الوثيقة لا تطابق المستلم المعتمد؛ سجّل «إحالة» للمراجعة.');
            if (input.attested !== true) throw new Error('أقرّ بأنك تحققت من هوية المستلم.');
            if (!input.signaturePng || !/^data:image\/png;base64,/.test(input.signaturePng)) throw new Error('توقيع المستلم بخط اليد مطلوب؛ إن تعذر سجّل «لم يُسلَّم» بسبب تعذر التوقيع.');
            const items = {};
            for (const item of entry.items) {
                const qty = Number(input.items?.[item.id] ?? 0);
                if (qty < 0 || qty > local.remaining[item.id] + 0.0001) throw new Error(`كمية «${item.label}» تتجاوز المتبقي (${local.remaining[item.id]}).`);
                if (qty > 0) items[item.id] = qty;
            }
            if (!Object.keys(items).length) throw new Error('حدد الكميات المسلّمة.');
            const full = entry.items.every(i => (items[i.id] || 0) >= local.remaining[i.id] - 0.0001);
            payload.kind = full ? 'delivered' : 'partial';
            payload.items = items;
            payload.receiver = { relation: 'guardian', id_last4: String(input.idLast4), method: input.method || 'id_card', attested: true };
            image = input.signaturePng.replace(/^data:image\/png;base64,/, '');
            payload.signature_sha256 = crypto.createHash('sha256').update(Buffer.from(image, 'base64')).digest('hex');
        } else if (kind === 'not_delivered' || kind === 'referral') {
            if (!manifest.failure_reasons.some(r => r.code === input.reason)) throw new Error('اختر سبب عدم التسليم.');
            payload.failure_reason = input.reason;
            payload.items = {};
            payload.receiver = input.relation ? { relation: input.relation, attested: Boolean(input.attested) } : null;
        } else {
            throw new Error('إجراء غير معروف.');
        }
        payload.note = String(input.note || '').slice(0, 500);
        const record = this.outbox.append(payload, text => this.device.sign(text), image);
        return { uuid: record.uuid, state: record.state, kind: payload.kind };
    }

    /** Uploads pending events in order; every acknowledgement is stored; a timeout keeps everything for the next attempt. */
    async sync() {
        this.requireUnlocked();
        const pending = this.outbox.pending();
        const results = [];
        for (let i = 0; i < pending.length; i += 50) {
            const chunk = pending.slice(i, i + 50);
            this.outbox.markUploading(chunk.map(e => e.uuid));
            const { data } = await this.api.sync(chunk.map(e => ({ payload: e.payload, signature: e.signature, signature_image: e.signature_image })));
            for (const ack of data.acks) {
                if (ack.event_uuid) this.outbox.acknowledge(ack.event_uuid, ack);
                results.push(ack);
            }
        }
        return { sent: pending.length, results };
    }

    async signOut() {
        try {
            if (this.api?.token) await this.api.signOut();
        } catch {
            // Offline: the server session simply expires; nothing local depends on it.
        }
        this.wipeSession();
        return { pending: this.outbox.pending().length };
    }

    wipeSession() {
        this.vault.remove('session');
        this.vault.remove('pin');
        for (const id of this.manifestIds()) {
            this.vault.remove(`manifest-${id}`);
            this.vault.remove(`clock-${id}`);
        }
        if (this.api) this.api.token = null;
        this.locked = true;
    }

    requireServer() {
        if (!this.api) throw new Error('حدد عنوان الخادم وثبّت مفتاحه أولًا.');
    }

    requireUnlocked() {
        this.requireServer();
        if (this.locked) throw new Error('الجهاز مقفل؛ أدخل رمز القفل.');
    }
}

module.exports = { FieldService };
