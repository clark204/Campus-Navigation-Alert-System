import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { retryText } from '../../resources/js/retry-countdown.js';

describe('retry countdown: the "try again" sentence', () => {
    test('counts the seconds left, singular at one', () => {
        assert.equal(retryText(49), 'Please try again in 49 seconds.');
        assert.equal(retryText(1), 'Please try again in 1 second.');
    });

    test('says the wait is over at zero', () => {
        assert.equal(retryText(0), 'You can try again now.');
        assert.equal(retryText(-3), 'You can try again now.');
    });
});
