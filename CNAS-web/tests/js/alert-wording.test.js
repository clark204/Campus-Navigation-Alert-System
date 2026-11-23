import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { counterState, kindLabel } from '../../resources/js/alert-wording.js';
import { alertKind, sentTime } from '../../resources/js/alerts/fill.js';

describe('alert wording', () => {
    test('the kind line says the level and type, or DRILL for a drill', () => {
        assert.equal(kindLabel({ severity: 'emergency', typeLabel: 'Fire', isDrill: false }), 'EMERGENCY · FIRE');
        assert.equal(kindLabel({ severity: 'emergency', typeLabel: 'Fire', isDrill: true }), 'DRILL · FIRE');
        assert.equal(kindLabel({ severity: 'warning', typeLabel: 'Flood', isDrill: false }), 'WARNING · FLOOD');
    });

    test('the takeover uses the same kind line as the preview', () => {
        assert.equal(alertKind({ severity: 'emergency', severity_label: 'Emergency', type_label: 'Earthquake', is_drill: false }), 'EMERGENCY · EARTHQUAKE');
        assert.equal(alertKind({ severity: 'emergency', severity_label: 'Emergency', type_label: 'Fire', is_drill: true }), 'DRILL · FIRE');
        assert.equal(alertKind(null), '');
    });

    test('the counter warns when 10 or fewer characters are left', () => {
        assert.deepEqual(counterState(12, 60), { text: '12/60', near: false });
        assert.deepEqual(counterState(50, 60), { text: '50/60', near: true });
        assert.deepEqual(counterState(100, 100), { text: '100/100', near: true });
    });

    test('the time sent is in the device clock, empty without one', () => {
        assert.match(sentTime({ sent_at: '2026-11-23T06:14:00Z' }, 'en-US'), /^Sent at \d{1,2}:14 (AM|PM)$/);
        assert.equal(sentTime(null), '');
    });
});
