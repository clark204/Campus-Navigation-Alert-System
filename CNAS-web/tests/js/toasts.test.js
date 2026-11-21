import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { announcementText, stackOffsets } from '../../resources/js/toasts.js';

/** A stand-in for the toast's message element: just the paragraphs' text. */
const message = (...lines) => ({
    querySelectorAll: () => lines.map((text) => ({ textContent: text })),
});

describe('toasts: what screen readers hear', () => {
    test('the title and body are read as two sentences', () => {
        assert.equal(announcementText(message('Email verified', 'Welcome to TCC NAV, Juan!')), 'Email verified. Welcome to TCC NAV, Juan!');
    });

    test('a toast with only a title reads just the title, without stray spaces', () => {
        assert.equal(announcementText(message('  Email verified  ')), 'Email verified');
        assert.equal(announcementText(message('Saved', '   ')), 'Saved');
    });
});

describe('toasts: stacking', () => {
    test('each toast sits above the ones below it, with a gap', () => {
        assert.deepEqual(stackOffsets([80, 120, 60], 12), [0, 92, 224]);
    });

    test('one toast sits at the bottom spot', () => {
        assert.deepEqual(stackOffsets([80]), [0]);
    });
});
