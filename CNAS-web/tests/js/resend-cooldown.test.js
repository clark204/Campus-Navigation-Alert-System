import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { cooldownLabel, secondsLeft } from '../../resources/js/resend-cooldown.js';

describe('resend code cooldown', () => {
    test('the button counts down, then reads "Resend code"', () => {
        assert.equal(cooldownLabel(42), 'Resend code in 42 s');
        assert.equal(cooldownLabel(1), 'Resend code in 1 s');
        assert.equal(cooldownLabel(0), 'Resend code');
    });

    test('seconds left round up and never go below zero', () => {
        assert.equal(secondsLeft(10_000, 0), 10);
        assert.equal(secondsLeft(10_000, 9_001), 1);
        assert.equal(secondsLeft(10_000, 10_000), 0);
        assert.equal(secondsLeft(10_000, 12_000), 0);
    });
});
