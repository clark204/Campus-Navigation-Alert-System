import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { acceptFor } from '../../resources/js/place-photos.js';

describe('place photos: upload file types', () => {
    test('a normal photo accepts JPEG, PNG and WebP', () => {
        assert.equal(acceptFor('photo'), 'image/jpeg,image/png,image/webp');
    });

    test('a 360° photo accepts JPEG only, like the walkway 360° photos', () => {
        assert.equal(acceptFor('panorama'), 'image/jpeg');
    });
});
