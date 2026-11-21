import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import {
    closeModal,
    createUploadModal,
    openModal,
    pickError,
    startUpload,
    submitAllowed,
    uploadFailed,
} from '../../resources/js/panorama-upload-state.js';

const MAX = 40 * 1024 * 1024;
const TOO_BIG = 'This photo is too big. The server accepts photos up to 40 MB.';

describe('upload dialog: opening and closing', () => {
    test('it starts closed, and opens with no error', () => {
        const state = createUploadModal();
        assert.deepEqual(state, { open: false, uploading: false, error: '' });
        assert.deepEqual(openModal(state), { open: true, uploading: false, error: '' });
    });

    test('it closes from Esc, the close button or a click outside, when idle', () => {
        const open = openModal(createUploadModal());
        assert.equal(closeModal(open).open, false);
    });

    test('it cannot be closed while a photo is being sent', () => {
        const sending = startUpload(openModal(createUploadModal()));
        assert.equal(closeModal(sending).open, true);
        assert.equal(closeModal(sending).uploading, true);
    });

    test('closing clears the error, so the next open starts clean', () => {
        const failed = uploadFailed(openModal(createUploadModal()), 'Choose a JPEG photo (.jpg).');
        assert.equal(closeModal(failed).error, '');
    });
});

describe('upload dialog: what may be sent', () => {
    test('a file within the limit may be sent', () => {
        assert.equal(submitAllowed(openModal(createUploadModal()), 10 * 1024 * 1024, MAX), true);
        assert.equal(submitAllowed(openModal(createUploadModal()), MAX, MAX), true);
    });

    test('a file over the limit is refused before it is sent, with the server\'s message', () => {
        const state = openModal(createUploadModal());
        assert.equal(submitAllowed(state, MAX + 1, MAX), false);
        assert.equal(pickError(MAX + 1, MAX, TOO_BIG), TOO_BIG);
        assert.equal(pickError(MAX, MAX, TOO_BIG), '');
    });

    test('a second send is not started while the first is in progress (a double click sends once)', () => {
        const sending = startUpload(openModal(createUploadModal()));
        assert.equal(sending.uploading, true);
        assert.equal(submitAllowed(sending, 1024, MAX), false);
    });

    test('the state after a send that came back is open again and ready for another try', () => {
        const back = uploadFailed(startUpload(openModal(createUploadModal())), 'Enter a name.');
        assert.deepEqual(back, { open: true, uploading: false, error: 'Enter a name.' });
        assert.equal(submitAllowed(back, 1024, MAX), true);
    });
});

describe('upload dialog: the server\'s errors reopen it', () => {
    test('a page that came back with errors opens the dialog with them shown', () => {
        const reopened = openModal(createUploadModal(), 'Choose a JPEG photo (.jpg).');
        assert.deepEqual(reopened, { open: true, uploading: false, error: 'Choose a JPEG photo (.jpg).' });
    });

    test('the errors are not lost when the dialog is reopened', () => {
        const failed = uploadFailed(createUploadModal(), 'Choose a full 360° photo.');
        assert.equal(failed.open, true);
        assert.equal(failed.error, 'Choose a full 360° photo.');
    });
});
