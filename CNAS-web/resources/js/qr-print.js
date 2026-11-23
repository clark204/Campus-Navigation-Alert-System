// A place's QR code page (admin/qr-code-page, the "QR code" button on Admin > Rooms and Buildings): Print, Download
// PNG, and the door picker for a building with several doors. The PNG is drawn in the browser from the card's own SVG
// code and logos (no library, no request): logos, code, name, where it is, and "Scan to start directions from here".

const WIDTH = 1200;
const HEIGHT = 1500;
const MAROON = '#581825';
const GOLD = '#C59B27';
const TEXT = '#1f1f1f';

const loadImage = (src) =>
    new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error(`Could not load ${src}`));
        image.src = src;
    });

// The server's SVG has only a viewBox; a canvas needs a size to draw it sharp.
export function sizedSvg(svgMarkup, size) {
    return svgMarkup.replace(/<svg\b/, `<svg width="${size}" height="${size}"`);
}

// The largest font size (px) at which `text` fits `maxWidth`, from `start` down to `min`.
export function fitFont(measure, text, maxWidth, start, min = 24) {
    let size = start;
    while (size > min && measure(text, size) > maxWidth) size -= 2;
    return size;
}

async function drawCard(card) {
    const canvas = document.createElement('canvas');
    canvas.width = WIDTH;
    canvas.height = HEIGHT;
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, WIDTH, HEIGHT);

    const svg = card.querySelector('[data-qr-svg] svg').outerHTML;
    const qrUrl = URL.createObjectURL(new Blob([sizedSvg(svg, 900)], { type: 'image/svg+xml' }));
    try {
        const [cnas, tcc, qr] = await Promise.all([...[...card.querySelectorAll('[data-logo]')].map((img) => loadImage(img.src)), loadImage(qrUrl)]);
        context.drawImage(cnas, 70, 50, 150, 150);
        context.drawImage(tcc, WIDTH - 210, 55, 140, 140);
        context.fillStyle = MAROON;
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.font = '600 40px "Instrument Sans", system-ui, sans-serif';
        context.fillText('Campus Navigation', WIDTH / 2, 125);
        context.drawImage(qr, 150, 220, 900, 900);
    } finally {
        URL.revokeObjectURL(qrUrl);
    }

    const measure = (text, size, weight = 700) => {
        context.font = `${weight} ${size}px "Instrument Sans", system-ui, sans-serif`;
        return context.measureText(text).width;
    };
    const title = card.querySelector('[data-qr-title]').textContent.trim();
    const detail = card.querySelector('[data-qr-detail]').textContent.trim();
    const titleSize = fitFont(measure, title, WIDTH - 120, 88);
    context.font = `700 ${titleSize}px "Instrument Sans", system-ui, sans-serif`;
    context.fillStyle = MAROON;
    context.fillText(title, WIDTH / 2, 1195);

    const detailSize = fitFont((text, size) => measure(text, size, 400), detail, WIDTH - 120, 46);
    context.font = `400 ${detailSize}px "Instrument Sans", system-ui, sans-serif`;
    context.fillStyle = TEXT;
    context.fillText(detail, WIDTH / 2, 1275);

    // "Scan to start directions from here" on a gold pill.
    const cta = 'Scan to start directions from here';
    context.font = '600 44px "Instrument Sans", system-ui, sans-serif';
    const pill = context.measureText(cta).width + 80;
    context.fillStyle = `${GOLD}40`;
    context.beginPath();
    context.roundRect((WIDTH - pill) / 2, 1335, pill, 84, 42);
    context.fill();
    context.fillStyle = MAROON;
    context.fillText(cta, WIDTH / 2, 1378);

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
}

export function initQrPrint(sheet) {
    const cards = [...sheet.querySelectorAll('[data-qr-card]')];
    const shown = () => cards.find((card) => !card.hidden) ?? cards[0];
    const show = (door) => cards.forEach((card) => (card.hidden = card.dataset.door !== door));

    document.querySelector('[data-qr-print-page]')?.addEventListener('click', () => window.print());

    // A building with several doors: the picked door's card is the one printed and saved.
    const doors = sheet.querySelector('[data-qr-doors]');
    if (doors) {
        doors.addEventListener('change', (event) => show(event.target.value));
        const ids = cards.map((card) => card.dataset.door);
        import('./main-entrance.js')
            .then(({ findMainEntrance }) => findMainEntrance(sheet.dataset.mapUrl, ids))
            .then((main) => {
                const choice = doors.querySelector(`[data-door-choice="${main}"]`);
                if (!choice) return;
                choice.querySelector('[data-main-badge]').hidden = false;
                doors.prepend(choice);
                // Start on the main entrance, unless the admin already picked a door while the map loaded.
                if (!doors.dataset.touched) {
                    choice.querySelector('input').checked = true;
                    show(main);
                }
            })
            .catch(() => {
                // No badge: every door can still be picked.
            });
        doors.addEventListener('change', () => (doors.dataset.touched = '1'), { once: true });
    }

    const png = document.querySelector('[data-qr-png]');
    png?.addEventListener('click', async () => {
        const card = shown();
        const label = png.textContent;
        png.disabled = true;
        png.textContent = 'Saving…';
        try {
            await document.fonts?.ready;
            const blob = await drawCard(card);
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${card.dataset.file}.png`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
            png.textContent = label;
        } catch {
            png.textContent = 'Couldn’t save. Try again';
        } finally {
            png.disabled = false;
        }
    });
}
