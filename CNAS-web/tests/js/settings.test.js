import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { MAX_RETRY_MS, POLL_MS, nextDelay } from '../../resources/js/push-subscribers-live.js';
import { isFieldDirty, nextTabIndex, unsavedChangesMessage } from '../../resources/js/settings-tabs.js';

describe('settings tabs: keyboard', () => {
    test('the arrow keys move to the next and previous tab and wrap around', () => {
        assert.equal(nextTabIndex('ArrowRight', 0, 4), 1);
        assert.equal(nextTabIndex('ArrowRight', 3, 4), 0);
        assert.equal(nextTabIndex('ArrowLeft', 0, 4), 3);
        assert.equal(nextTabIndex('ArrowDown', 1, 4), 2);
        assert.equal(nextTabIndex('ArrowUp', 1, 4), 0);
    });

    test('Home and End go to the first and last tab; other keys are left alone', () => {
        assert.equal(nextTabIndex('Home', 2, 4), 0);
        assert.equal(nextTabIndex('End', 0, 4), 3);
        assert.equal(nextTabIndex('Enter', 1, 4), null);
        assert.equal(nextTabIndex('a', 1, 4), null);
    });
});

describe('settings tabs: unsaved changes', () => {
    test('a text field is changed when its value differs from the one it loaded with', () => {
        assert.equal(isFieldDirty({ type: 'email', tagName: 'INPUT', value: 'new@tcc.edu.ph', defaultValue: 'old@tcc.edu.ph' }), true);
        assert.equal(isFieldDirty({ type: 'email', tagName: 'INPUT', value: 'old@tcc.edu.ph', defaultValue: 'old@tcc.edu.ph' }), false);
        assert.equal(isFieldDirty({ type: 'password', tagName: 'INPUT', value: 'typed', defaultValue: '' }), true);
    });

    test('a checkbox is changed when it is ticked differently from the start', () => {
        assert.equal(isFieldDirty({ type: 'checkbox', tagName: 'INPUT', checked: false, defaultChecked: true }), true);
        assert.equal(isFieldDirty({ type: 'checkbox', tagName: 'INPUT', checked: true, defaultChecked: true }), false);
    });

    test('a select is changed when a different option is chosen', () => {
        const select = (selectedIndex) => ({
            tagName: 'SELECT',
            options: [0, 1].map((index) => ({ selected: index === selectedIndex, defaultSelected: index === 0 })),
        });
        assert.equal(isFieldDirty(select(1)), true);
        assert.equal(isFieldDirty(select(0)), false);
    });

    test('the warning names the tabs whose changes would be lost', () => {
        assert.equal(unsavedChangesMessage(['Contact details']), 'You have unsaved changes in Contact details. Saving this will discard them. Continue?');
        assert.equal(
            unsavedChangesMessage(['Contact details', 'Emergency contacts', 'My account']),
            'You have unsaved changes in Contact details, Emergency contacts and My account. Saving this will discard them. Continue?',
        );
    });
});

describe('live push subscribers: retry delay', () => {
    test('asks every 10 seconds while the server answers', () => {
        assert.equal(nextDelay(0), POLL_MS);
        assert.equal(POLL_MS, 10_000);
    });

    test('waits twice as long after each failure in a row, up to a minute', () => {
        assert.equal(nextDelay(1), 20_000);
        assert.equal(nextDelay(2), 40_000);
        assert.equal(nextDelay(3), MAX_RETRY_MS);
        assert.equal(nextDelay(10), 60_000);
    });
});
