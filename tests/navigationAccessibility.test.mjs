import assert from 'node:assert/strict';
import { test } from 'node:test';
import { filterNavigation } from '../resources/js/Support/navigationFilter.js';
import { navigationIcon } from '../resources/js/Support/navigationIcons.js';
import { menuIconPaths } from '../resources/js/Support/menuIcons.js';
import { activateNavigationDrawer } from '../resources/js/Support/navigationDrawer.js';

test('Arabic search keeps only authorized links, preserves destinations and does not mutate the menu', () => {
    const tree = [{ code: 'hr', label: 'الموظفون', children: [
        { code: 'attendance', label: 'الحُضور والانصراف', url: '/admin/attendance?tab=ledger' },
        { code: 'files', label: 'ملفات الموظفين', url: '/admin/employee-profiles' },
    ] }];
    const original = structuredClone(tree);
    assert.deepEqual(filterNavigation(tree, 'الحضور')[0].children, [tree[0].children[0]]);
    assert.deepEqual(filterNavigation(tree, 'المالية'), []);
    assert.deepEqual(filterNavigation(tree, 'الموظفون'), tree);
    assert.deepEqual(filterNavigation(tree, '  '), tree);
    assert.deepEqual(tree, original);
});

test('semantic icons preserve administrator choices and reject arbitrary SVG values', () => {
    assert.equal(navigationIcon({ code: 'attendance', icon: 'calendar' }, menuIconPaths), 'attendance');
    assert.equal(navigationIcon({ code: 'attendance', icon: 'heart' }, menuIconPaths), 'heart');
    assert.equal(navigationIcon({ icon: '<svg onload=alert(1)>' }, menuIconPaths), 'folder');
    assert.equal(navigationIcon({ icon: '__proto__' }, menuIconPaths), 'folder');
});

test('mobile drawer traps focus, closes with Escape and restores scroll, focus and listeners', () => {
    const listeners = {};
    const doc = { body: { style: { overflow: 'auto' } }, activeElement: null,
        addEventListener: (name, callback) => { listeners[name] = callback; },
        removeEventListener: name => { delete listeners[name]; },
    };
    const element = () => ({ isConnected: true, getClientRects: () => [1], closest: () => null, focus() { doc.activeElement = this; } });
    const first = element(), last = element(), trigger = element();
    const background = { inert: false };
    const panel = { querySelectorAll: () => [first, last], contains: target => [first, last].includes(target) };
    let closed = false;
    const release = activateNavigationDrawer(panel, background, trigger, () => { closed = true; }, doc);
    assert.equal(doc.activeElement, first);
    assert.equal(background.inert, true);
    assert.equal(doc.body.style.overflow, 'hidden');
    listeners.keydown({ key: 'Tab', shiftKey: true, preventDefault() {} });
    assert.equal(doc.activeElement, last);
    listeners.keydown({ key: 'Tab', shiftKey: false, preventDefault() {} });
    assert.equal(doc.activeElement, first);
    listeners.keydown({ key: 'Escape', preventDefault() {} });
    assert.equal(closed, true);
    release();
    assert.equal(background.inert, false);
    assert.equal(doc.body.style.overflow, 'auto');
    assert.equal(doc.activeElement, trigger);
    assert.deepEqual(listeners, {});
});
