import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { generatePassword, initialsOf, listUrl, matchesConfirmation, showsForRole, withSearch } from '../../resources/js/accounts.js';

describe('accounts: temporary password', () => {
    test('meets the password rules: 12 characters with letters and numbers', () => {
        for (let i = 0; i < 200; i++) {
            const password = generatePassword();
            assert.equal(password.length, 12);
            assert.match(password, /[A-Za-z].*[A-Za-z]/);
            assert.match(password, /\d.*\d/);
        }
    });

    test('never uses characters that look alike when read out', () => {
        const all = Array.from({ length: 200 }, () => generatePassword()).join('');
        assert.doesNotMatch(all, /[01OIl]/);
    });

    test('is random: two in a row differ', () => {
        assert.notEqual(generatePassword(), generatePassword());
    });
});

describe('accounts: create form', () => {
    test('the preview initials come from the first and last name', () => {
        assert.equal(initialsOf(' maria ', 'santos'), 'MS');
        assert.equal(initialsOf('', ''), '?');
    });

    test('fields follow the chosen role', () => {
        assert.equal(showsForRole('student faculty', 'faculty'), true);
        assert.equal(showsForRole('faculty staff', 'student'), false);
    });
});

describe('accounts: live search', () => {
    test('the list URL keeps the filters, drops an empty search and starts again at page 1', () => {
        assert.equal(listUrl('/admin/accounts?page=3', { role: 'staff', q: '  juan ', trusted: '' }), '/admin/accounts?role=staff&q=juan');
        assert.equal(listUrl('https://cnas.test/admin/accounts', { q: '   ' }), 'https://cnas.test/admin/accounts');
    });

    test('filter links follow the search, and lose it when the box is cleared', () => {
        assert.equal(withSearch('/admin/accounts?role=faculty', 'santos'), '/admin/accounts?role=faculty&q=santos');
        assert.equal(withSearch('/admin/accounts?role=faculty&q=old', ''), '/admin/accounts?role=faculty');
    });
});

describe('accounts: danger zone', () => {
    test('delete unlocks only for the exact email, ignoring case and spaces', () => {
        assert.equal(matchesConfirmation(' Juan@TCC.edu.ph ', 'juan@tcc.edu.ph'), true);
        assert.equal(matchesConfirmation('juan@tcc.edu', 'juan@tcc.edu.ph'), false);
        assert.equal(matchesConfirmation('', 'juan@tcc.edu.ph'), false);
    });
});
