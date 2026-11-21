// Where the hover preview of a photo goes: beside its thumbnail, on the side with room, kept inside the window and
// centred on the thumbnail's height. Pure, so it can be tested in Node.
const GAP = 12;
const MARGIN = 8;

// `anchor` and `viewport` are { left, top, right, bottom, width, height } rectangles' sides; `size` is { width, height }
// of the preview. Returns { left, top } for the preview's top-left corner.
export function previewPlacement(anchor, size, viewport) {
    const rightOf = anchor.right + GAP;
    const leftOf = anchor.left - GAP - size.width;
    // To the right of the thumbnail, unless that runs off the window and the left side has room.
    const fitsRight = rightOf + size.width <= viewport.width - MARGIN;
    const fitsLeft = leftOf >= MARGIN;
    const left = fitsRight || !fitsLeft ? Math.min(rightOf, viewport.width - MARGIN - size.width) : leftOf;

    const centred = anchor.top + anchor.height / 2 - size.height / 2;
    const top = Math.min(Math.max(centred, MARGIN), viewport.height - MARGIN - size.height);
    return { left: Math.max(MARGIN, left), top: Math.max(MARGIN, top) };
}
