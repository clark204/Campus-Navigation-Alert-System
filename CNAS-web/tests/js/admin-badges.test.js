// The admin sidebar's live badge (resources/js/admin-badges.js).
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import { badgeState } from '../../resources/js/admin-badges.js';

describe('live sidebar badge', () => {
    test('shows the count, hides at zero, caps at 99+', () => {
        assert.deepEqual(badgeState(3), { text: '3', hidden: false });
        assert.deepEqual(badgeState(0), { text: '0', hidden: true });
        assert.deepEqual(badgeState(150), { text: '99+', hidden: false });
    });

    test('keeps what is shown when the answer is not a count', () => {
        assert.equal(badgeState(undefined), null);
        assert.equal(badgeState('4'), null);
        assert.equal(badgeState(-1), null);
    });
});
