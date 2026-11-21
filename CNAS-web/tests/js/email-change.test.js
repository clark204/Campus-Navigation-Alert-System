import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { submitState } from '../../resources/js/email-change.js';

describe('email change: the Settings submit button', () => {
    test('a verified email shows no button until a different address is typed', () => {
        assert.deepEqual(submitState('juan@tcc.edu.ph', 'juan@tcc.edu.ph', true), { hidden: true, label: 'Send code' });
        assert.deepEqual(submitState('juan2@tcc.edu.ph', 'juan@tcc.edu.ph', true), { hidden: false, label: 'Send code' });
    });

    test('an unverified email offers "Verify email" while the field holds it', () => {
        assert.deepEqual(submitState('juan@tcc.edu.ph', 'juan@tcc.edu.ph', false), { hidden: false, label: 'Verify email' });
        assert.deepEqual(submitState('other@tcc.edu.ph', 'juan@tcc.edu.ph', false), { hidden: false, label: 'Send code' });
    });

    test('case and surrounding spaces do not count as a different address', () => {
        assert.equal(submitState('  Juan@TCC.edu.ph ', 'juan@tcc.edu.ph', true).hidden, true);
    });
});
