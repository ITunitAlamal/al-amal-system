import assert from 'node:assert/strict';
import { test } from 'node:test';
import { householdResultsUrl, recordResultsUrl, recordWithResults, registryResultsUrl } from '../resources/js/Support/orphanNavigation.js';

test('a record return link retains the page, filters and selected household anchor', () => {
    const results = householdResultsUrl('/orphans?status=active&page=3', 'synthetic-family');
    const household = recordWithResults('/orphans/households/synthetic-family', results);
    const child = recordWithResults('/orphans/children/synthetic-child', recordResultsUrl(household));

    assert.equal(recordResultsUrl(child), '/orphans?status=active&page=3#household-synthetic-family');
    assert.equal(householdResultsUrl(results, 'other-family'), '/orphans?status=active&page=3#household-other-family');
});

test('return anchors accept only household identifiers and strip unrelated query state', () => {
    assert.equal(registryResultsUrl('/orphans?token=secret#household-synthetic'), '/orphans#household-synthetic');
    assert.equal(registryResultsUrl('/orphans#javascript:alert(1)'), '/orphans');
    assert.equal(householdResultsUrl('/orphans', '../../admin'), '/orphans');
});
