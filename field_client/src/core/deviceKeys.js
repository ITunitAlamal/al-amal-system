'use strict';

// The device's own Ed25519 key. Generated once; the private key lives only inside the encrypted vault. It proves
// possession at sign-in and signs every delivery event, so the server can tell this device's evidence from any other.
const crypto = require('crypto');

function loadOrCreate(vault) {
    let record = vault.read('device-key');
    if (!record) {
        const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
        record = {
            private: privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64'),
            public: Buffer.from(publicKey.export({ format: 'jwk' }).x, 'base64url').toString('base64'),
            createdAt: new Date().toISOString(),
        };
        vault.write('device-key', record);
    }
    const privateKey = crypto.createPrivateKey({ key: Buffer.from(record.private, 'base64'), format: 'der', type: 'pkcs8' });
    const raw = Buffer.from(record.public, 'base64');
    return {
        publicKeyBase64: record.public,
        fingerprint: crypto.createHash('sha256').update(raw).digest('hex'),
        sign: message => crypto.sign(null, Buffer.from(message, 'utf8'), privateKey).toString('base64'),
    };
}

/** Verifies a server signature over the exact manifest text with the pinned server public key (raw 32 bytes, base64). */
function verifyServer(message, signatureBase64, publicKeyBase64) {
    try {
        const key = crypto.createPublicKey({ key: { kty: 'OKP', crv: 'Ed25519', x: Buffer.from(publicKeyBase64, 'base64').toString('base64url') }, format: 'jwk' });
        return crypto.verify(null, Buffer.from(message, 'utf8'), key, Buffer.from(signatureBase64, 'base64'));
    } catch {
        return false;
    }
}

module.exports = { loadOrCreate, verifyServer };
