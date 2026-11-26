import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createUuid } from '../resources/js/Support/uuid.js';

test('UUIDs use the browser native generator when it is available', () => {
    const uuid = '64ea1a6a-67ef-4efb-93b1-38cf7ef9fd61';
    const cryptoProvider = { randomUUID: () => uuid };

    assert.equal(createUuid(cryptoProvider), uuid);
});

test('UUIDs fall back to secure random bytes when randomUUID is unavailable', () => {
    const cryptoProvider = { getRandomValues: bytes => bytes.fill(0) };

    assert.equal(createUuid(cryptoProvider), '00000000-0000-4000-8000-000000000000');
});

test('UUIDs remain valid when the browser has no crypto provider', () => {
    const uuid = createUuid(null);

    assert.match(uuid, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});
