'use strict';

// Encrypted local storage. A random 256-bit data key is wrapped by the operating system (Windows DPAPI through
// Electron safeStorage) and never written in clear; every record is AES-256-GCM sealed with its own name as
// authenticated data, so a record cannot be read, edited or swapped with another without detection. Writes are
// atomic (temporary file + rename). Nothing is kept in plaintext: no identity list, signature or token on disk.
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const VERSION = 1;

class Vault {
    /**
     * @param {string} dir directory inside the per-user application data folder
     * @param {{encrypt(buf: Buffer): Buffer, decrypt(buf: Buffer): Buffer}} protector OS-bound key wrapping
     */
    constructor(dir, protector) {
        this.dir = dir;
        this.protector = protector;
        fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
        this.key = this.loadKey();
    }

    loadKey() {
        const file = path.join(this.dir, 'vault.key');
        if (fs.existsSync(file)) {
            const key = this.protector.decrypt(fs.readFileSync(file));
            if (key.length !== 32) throw new Error('vault key unreadable');
            return key;
        }
        const key = crypto.randomBytes(32);
        this.atomicWrite(file, this.protector.encrypt(key));
        return key;
    }

    seal(name, value) {
        const iv = crypto.randomBytes(12);
        const cipher = crypto.createCipheriv('aes-256-gcm', this.key, iv);
        cipher.setAAD(Buffer.from(`aims:${VERSION}:${name}`));
        const body = Buffer.concat([cipher.update(Buffer.from(JSON.stringify(value), 'utf8')), cipher.final()]);
        return Buffer.concat([Buffer.from([VERSION]), iv, cipher.getAuthTag(), body]);
    }

    open(name, sealed) {
        if (sealed[0] !== VERSION) throw new Error('unknown vault record version');
        const decipher = crypto.createDecipheriv('aes-256-gcm', this.key, sealed.subarray(1, 13));
        decipher.setAAD(Buffer.from(`aims:${VERSION}:${name}`));
        decipher.setAuthTag(sealed.subarray(13, 29));
        return JSON.parse(Buffer.concat([decipher.update(sealed.subarray(29)), decipher.final()]).toString('utf8'));
    }

    file(name) {
        if (!/^[a-z0-9._-]+$/i.test(name)) throw new Error('invalid record name');
        return path.join(this.dir, `${name}.bin`);
    }

    write(name, value) {
        this.atomicWrite(this.file(name), this.seal(name, value));
    }

    read(name, fallback = null) {
        const file = this.file(name);
        if (!fs.existsSync(file)) return fallback;
        return this.open(name, fs.readFileSync(file));
    }

    has(name) {
        return fs.existsSync(this.file(name));
    }

    /** Only cached data (manifests, session) is ever removed; the outbox is never deleted before the server acknowledges it. */
    remove(name) {
        const file = this.file(name);
        if (fs.existsSync(file)) fs.rmSync(file);
    }

    list(prefix) {
        return fs.readdirSync(this.dir).filter(f => f.startsWith(prefix) && f.endsWith('.bin')).map(f => f.slice(0, -4)).sort();
    }

    atomicWrite(file, buffer) {
        const tmp = `${file}.${process.pid}.${crypto.randomBytes(4).toString('hex')}.tmp`;
        const fd = fs.openSync(tmp, 'w', 0o600);
        try {
            fs.writeSync(fd, buffer);
            fs.fsyncSync(fd);
        } finally {
            fs.closeSync(fd);
        }
        fs.renameSync(tmp, file);
    }
}

module.exports = { Vault };
