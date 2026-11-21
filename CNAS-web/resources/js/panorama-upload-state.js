// The "Upload photo" dialog on the admin 360° photos list: whether it is open, whether a photo is being sent, and the
// error to show. Pure, so the rules can be tested in Node; the DOM (panoramas-upload.js) follows it.
//
// The rules: a file over the server's limit is refused before it is sent; a second upload is not started while one is
// being sent; the dialog cannot be closed while a photo is being sent; and a dialog reopened by the server keeps its
// errors.

export const createUploadModal = () => ({ open: false, uploading: false, error: '' });

// Opens the dialog. `error` is what to show (a server message when the page came back with one).
export const openModal = (state, error = '') => ({ ...state, open: true, uploading: false, error });

// Closes the dialog, unless a photo is being sent (then it stays open, so the upload is not lost from view).
export const closeModal = (state) => (state.uploading ? state : { ...state, open: false, error: '' });

// Whether the file may be sent: none is already being sent, and it is not bigger than the server accepts.
export const submitAllowed = (state, size, maxBytes) => !state.uploading && size <= maxBytes;

// The error for a file chosen in the picker (empty when it is fine).
export const pickError = (size, maxBytes, tooBigMessage) => (size > maxBytes ? tooBigMessage : '');

// The form is being sent: the button shows "Uploading…" and cannot be pressed again.
export const startUpload = (state) => ({ ...state, uploading: true, error: '' });

// The page came back without the photo: the dialog stays open with the error.
export const uploadFailed = (state, error) => ({ ...state, open: true, uploading: false, error });
