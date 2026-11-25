// Builds the app icons from the CNAS logo (assets/images/logo.svg, same as CNAS-web/public/logo/nav-logo.svg).
// Run from CNAS-mobile: `node scripts/generate-icons.mjs`. Pass `--preview <dir>` to also write preview images
// (icon on light and dark, adaptive icon in circle and squircle masks, monochrome).
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const images = join(root, 'assets/images');

const BG = '#FAF8F5'; // CNAS-web --color-bg-light, also the splash and intro background (src/theme.ts colors.bg)
const MAROON = '#581825'; // CNAS-web --color-primary
const GOLD = '#C59B26';

// The logo's shapes: the maroon pieces (gold outline) and the gold road. The SVG also holds empty Illustrator paths
// (a single moveto), which are skipped.
const svg = readFileSync(join(images, 'logo.svg'), 'utf8');
const paths = [...svg.matchAll(/<path\b[^>]*?\bfill="([^"]+)"[^>]*?\bd="([^"]+)"/gs)]
  .map(([, fill, d]) => ({ fill, d: d.replace(/\s+/g, ' ') }))
  .filter((p) => /[a-z]/i.test(p.d.slice(1)));
const pieces = paths.filter((p) => p.fill === MAROON).map((p) => p.d);
const road = paths.filter((p) => p.fill === GOLD).map((p) => p.d);
if (pieces.length !== 3 || road.length !== 1) throw new Error('logo.svg changed: expected 3 maroon pieces and 1 road');

// The triangle's corners in logo units; the logo is centered on the middle of their bounding box.
const CORNERS = [[56.69, 141.73], [141.73, 141.73], [99.21, 58.08]];
const CX = (56.69 + 141.73) / 2;
const CY = (58.08 + 141.73) / 2;
const LOGO_WIDTH = 141.73 - 56.69;
const FARTHEST = Math.max(...CORNERS.map(([x, y]) => Math.hypot(x - CX, y - CY)));

/** The logo drawn at `scale` px per logo unit, centered in a `size` px square. `mono` draws it in one color. */
function logo(size, scale, { mono } = {}) {
  const t = `translate(${size / 2} ${size / 2}) scale(${scale}) translate(${-CX} ${-CY})`;
  const fill = mono ? '#FFFFFF' : MAROON;
  const stroke = mono ? '#FFFFFF' : GOLD;
  return `<g transform="${t}">
    ${pieces.map((d) => `<path fill="${fill}" stroke="${stroke}" stroke-miterlimit="10" d="${d}"/>`).join('\n    ')}
    <path fill="${mono ? '#FFFFFF' : GOLD}" d="${road[0]}"/>
  </g>`;
}

const doc = (size, body) =>
  `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">${body}</svg>`;

async function png(svgText, file, { flatten } = {}) {
  let img = sharp(Buffer.from(svgText));
  if (flatten) img = img.flatten({ background: BG }).removeAlpha();
  await img.png({ compressionLevel: 9, palette: true, quality: 90, effort: 10 }).toFile(file);
}

const S = 1024;

// App icon (iOS and the fallback everywhere): full square, opaque, no rounded corners. Logo about 60% wide.
const iconSvg = doc(S, `<rect width="${S}" height="${S}" fill="${BG}"/>${logo(S, (0.6 * S) / LOGO_WIDTH)}`);
await png(iconSvg, join(images, 'icon.png'), { flatten: true });

// Android adaptive icon: 108dp canvas, launchers keep the middle 66dp circle. Every corner of the triangle stays inside
// a circle of 64% of the canvas, so no mask clips it.
const adaptiveScale = (0.32 * S) / FARTHEST;
await png(doc(S, logo(S, adaptiveScale)), join(images, 'android-icon-foreground.png'));
await png(doc(S, logo(S, adaptiveScale, { mono: true })), join(images, 'android-icon-monochrome.png'));

// Web favicon: the logo filling a 48 px square, transparent around it.
await png(doc(48, logo(48, 44 / LOGO_WIDTH)), join(images, 'favicon.png'));

// Optional previews.
const previewAt = process.argv.indexOf('--preview');
if (previewAt !== -1) {
  const out = process.argv[previewAt + 1];
  mkdirSync(out, { recursive: true });
  const T = 220; // one tile
  const icon = (await sharp(Buffer.from(iconSvg)).resize(160).png().toBuffer()).toString('base64');
  const fg = (await sharp(Buffer.from(doc(S, logo(S, adaptiveScale)))).resize(160).png().toBuffer()).toString('base64');
  const mono = (await sharp(Buffer.from(doc(S, logo(S, adaptiveScale, { mono: true })))).resize(160).png().toBuffer()).toString('base64');
  const img = (b64, x, y, clip) =>
    `<image x="${x}" y="${y}" width="160" height="160" href="data:image/png;base64,${b64}" ${clip ? `clip-path="url(#${clip})"` : ''}/>`;
  const squircle = 'M80 0C150 0 160 10 160 80S150 160 80 160 0 150 0 80 10 0 80 0Z';
  const tiles = [
    ['Icon, light', '#FFFFFF', '#212529', (x) => `<g clip-path="url(#ios)">${img(icon, x, 30)}</g>`],
    ['Icon, dark', '#121212', '#F2F2F2', (x) => `<g clip-path="url(#ios2)">${img(icon, x, 30)}</g>`],
    ['Adaptive, circle', '#E9E6E1', '#212529', (x) => `<circle cx="${x + 80}" cy="110" r="80" fill="${BG}"/>${img(fg, x, 30)}`],
    ['Adaptive, squircle', '#2B2B2B', '#F2F2F2', (x) => `<path transform="translate(${x} 30)" d="${squircle}" fill="${BG}"/>${img(fg, x, 30)}`],
    ['Monochrome (themed)', '#1F2A1F', '#F2F2F2', (x) => `<circle cx="${x + 80}" cy="110" r="80" fill="#3B4A3A"/><g opacity="0.95">${img(mono, x, 30)}</g>`],
  ];
  const W = T * tiles.length;
  const body = tiles
    .map(([label, bg, ink, draw], i) => {
      const x = i * T;
      return `<rect x="${x}" width="${T}" height="${T + 20}" fill="${bg}"/>${draw(x + 30)}<text x="${x + T / 2}" y="${T + 6}" fill="${ink}" font-family="Segoe UI, Arial" font-size="15" text-anchor="middle">${label}</text>`;
    })
    .join('');
  const defs = `<defs><clipPath id="ios"><rect x="30" y="30" width="160" height="160" rx="36"/></clipPath><clipPath id="ios2"><rect x="${T + 30}" y="30" width="160" height="160" rx="36"/></clipPath></defs>`;
  const sheet = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${T + 20}">${defs}${body}</svg>`;
  writeFileSync(join(out, 'icons-preview.svg'), sheet);
  await sharp(Buffer.from(sheet)).png().toFile(join(out, 'icons-preview.png'));
  console.log(`Preview: ${join(out, 'icons-preview.png')}`);
}

console.log('Wrote icon.png, android-icon-foreground.png, android-icon-monochrome.png, favicon.png');
