import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { matchesQuery, paginate } from '../../resources/js/table-search.js';

describe('table search: matching', () => {
    test('every word of the query must appear, in any order and any case', () => {
        assert.equal(matchesQuery('library building b8 the library', 'LIBRARY'), true);
        assert.equal(matchesQuery('library building b8 the library', 'the library'), true);
        assert.equal(matchesQuery('library building b8 the library', 'library hall'), false);
    });

    test('an empty or spaces-only query matches every row', () => {
        assert.equal(matchesQuery('nee building b1', ''), true);
        assert.equal(matchesQuery('nee building b1', '   '), true);
    });

    test('extra spaces in the query do not matter', () => {
        assert.equal(matchesQuery('nee 101 classroom', '  nee   101 '), true);
    });
});

describe('table search: paging', () => {
    const items = Array.from({ length: 23 }, (_, index) => index + 1);

    test('the first page holds the first ten items', () => {
        const view = paginate(items, 1, 10);
        assert.deepEqual(view.items, items.slice(0, 10));
        assert.deepEqual([view.page, view.pages, view.start], [1, 3, 0]);
    });

    test('the last page holds the rest', () => {
        const view = paginate(items, 3, 10);
        assert.deepEqual(view.items, [21, 22, 23]);
        assert.deepEqual([view.page, view.start], [3, 20]);
    });

    test('a page past the end is kept within the pages there are', () => {
        assert.equal(paginate(items, 9, 10).page, 3);
        assert.equal(paginate(items, 0, 10).page, 1);
    });

    test('an empty list has one empty page', () => {
        assert.deepEqual(paginate([], 1, 10), { items: [], page: 1, pages: 1, start: 0 });
    });
});
