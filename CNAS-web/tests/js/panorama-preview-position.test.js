import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { previewPlacement } from '../../resources/js/panorama-preview-position.js';

const VIEWPORT = { width: 1920, height: 1032 };
const SIZE = { width: 448, height: 240 };
const box = (left, top, width, height) => ({ left, top, width, height, right: left + width, bottom: top + height });

describe('hover preview placement', () => {
    test('beside the thumbnail, on the right, centred on its height', () => {
        const thumb = box(600, 400, 128, 64);
        assert.deepEqual(previewPlacement(thumb, SIZE, VIEWPORT), { left: 600 + 128 + 12, top: 400 + 32 - 120 });
    });

    test('on the left when the right side has no room (a thumbnail near the right edge)', () => {
        const thumb = box(1700, 400, 128, 64);
        const at = previewPlacement(thumb, SIZE, VIEWPORT);
        assert.equal(at.left, 1700 - 12 - 448);
    });

    test('kept inside the window at the bottom: a row near the foot of the screen moves the preview up', () => {
        const thumb = box(600, 1000, 128, 64);
        const at = previewPlacement(thumb, SIZE, VIEWPORT);
        assert.equal(at.top, VIEWPORT.height - 8 - SIZE.height);
    });

    test('kept inside the window at the top', () => {
        const thumb = box(600, 2, 128, 64);
        assert.equal(previewPlacement(thumb, SIZE, VIEWPORT).top, 8);
    });

    test('a narrow window puts the preview at the left margin rather than off the screen', () => {
        const thumb = box(100, 300, 128, 64);
        const at = previewPlacement(thumb, SIZE, { width: 500, height: 800 });
        assert.ok(at.left >= 8 && at.left + SIZE.width <= 500 + 8);
    });
});
