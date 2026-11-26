import assert from 'node:assert/strict';
import { test } from 'node:test';
import { queueUrl, recordResultsUrl, recordWithResults, registryResultsUrl } from '../resources/js/Support/orphanNavigation.js';

test('child and household navigation retain the original filtered results and page', () => {
    const results = '/orphans?q=001234567&status=registered&page=3';
    const household = recordWithResults('/orphans/households/example', results);
    const child = recordWithResults('/orphans/children/example', recordResultsUrl(household));

    assert.equal(recordResultsUrl(child), results);
    assert.equal(recordResultsUrl(household), results);
});

test('Arabic search text survives nested record links without altering its spelling', () => {
    const results = '/orphans?q=%D8%A3%D8%AD%D9%85%D8%AF&page=2';

    assert.equal(recordResultsUrl(recordWithResults('/orphans/children/example', results)), results);
});

test('return navigation never accepts an external, executable, or unrelated URL', () => {
    for (const target of ['https://example.com/orphans', '//example.com/orphans', 'javascript:alert(1)', '/admin/users', '/orphans/../admin', '/orphans#fragment', null]) {
        assert.equal(registryResultsUrl(target), '/orphans');
    }
});

test('return navigation removes unrelated parameters and invalid pagination or status', () => {
    assert.equal(registryResultsUrl('/orphans?token=secret&status=unknown&page=-1&tab=unknown'), '/orphans');
    assert.equal(registryResultsUrl('/orphans?q=001234567&token=secret&page=2'), '/orphans?q=001234567&page=2');
});

test('queue switching retains list filters and page for returning to the household list', () => {
    const queue = queueUrl('/orphans?status=active&page=4', 'visits');

    assert.equal(queue, '/orphans?status=active&page=4&tab=visits');
    assert.equal(queueUrl(queue, 'households'), '/orphans?status=active&page=4');
    assert.equal(queueUrl(queue, 'unsupported'), '/orphans?status=active&page=4');
});

test('directly opened records fall back to the registry', () => {
    assert.equal(recordResultsUrl('/orphans/children/example'), '/orphans');
    assert.equal(recordResultsUrl('/orphans/children/example?return_to=https%3A%2F%2Fevil.example'), '/orphans');
});
