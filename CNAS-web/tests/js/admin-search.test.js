import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { isSearchShortcut, nextActiveIndex, resultsSummary } from '../../resources/js/admin-search.js';

describe('admin search: choosing a result with the arrow keys', () => {
    test('ArrowDown goes to the first result, then down, and wraps to the top', () => {
        assert.equal(nextActiveIndex('ArrowDown', -1, 3), 0);
        assert.equal(nextActiveIndex('ArrowDown', 1, 3), 2);
        assert.equal(nextActiveIndex('ArrowDown', 2, 3), 0);
    });

    test('ArrowUp from nothing or the top goes to the last result', () => {
        assert.equal(nextActiveIndex('ArrowUp', -1, 3), 2);
        assert.equal(nextActiveIndex('ArrowUp', 0, 3), 2);
        assert.equal(nextActiveIndex('ArrowUp', 2, 3), 1);
    });

    test('with no results nothing is chosen', () => {
        assert.equal(nextActiveIndex('ArrowDown', -1, 0), -1);
    });
});

describe('admin search: the "/" shortcut', () => {
    const page = { tagName: 'BODY', isContentEditable: false };

    test('"/" on the page jumps to the search', () => {
        assert.equal(isSearchShortcut({ key: '/', target: page }), true);
    });

    test('not while typing in a field, and not with a modifier key', () => {
        for (const tagName of ['INPUT', 'TEXTAREA', 'SELECT']) assert.equal(isSearchShortcut({ key: '/', target: { tagName } }), false);
        assert.equal(isSearchShortcut({ key: '/', target: { tagName: 'DIV', isContentEditable: true } }), false);
        assert.equal(isSearchShortcut({ key: '/', ctrlKey: true, target: page }), false);
        assert.equal(isSearchShortcut({ key: 'a', target: page }), false);
    });
});

describe('admin search: what screen readers hear', () => {
    test('the number of results, or that nothing matched', () => {
        assert.equal(resultsSummary([]), 'No matches.');
        assert.equal(resultsSummary([{ results: [{}] }]), '1 result. Use the arrow keys to choose one.');
        assert.equal(resultsSummary([{ results: [{}, {}] }, { results: [{}] }]), '3 results. Use the arrow keys to choose one.');
    });
});
