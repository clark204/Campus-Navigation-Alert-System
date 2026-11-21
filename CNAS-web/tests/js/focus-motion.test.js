import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { focusFor } from '../../resources/js/navigation/focus.js';
import {
    DRAW_IN_MS,
    drawInFraction,
    FLOW_PERIOD_PIXELS,
    flowDashOffset,
    GLIDE_MS,
    glidePoint,
    motionFor,
    partialLines,
    pathData,
} from '../../resources/js/navigation/motion.js';
import { offset } from '../../resources/js/panoramas.js';
import { meters } from '../../resources/js/navigation/geo.js';

const START = [124.7482424, 8.5412066];
const east = (m) => offset(START, 90, m);
const length = (coords) => coords.reduce((sum, c, i) => (i ? sum + meters(coords[i - 1], c) : 0), 0);

describe('navigate: what is shown before, during and after navigation', () => {
    test('before Start: room dots show, highlights do not', () => {
        assert.deepEqual(focusFor({ navigating: false, streetView: false }), { entrances: true, streetViewLayers: false });
    });

    test('during navigation: room dots go; the route, dot and pins are not in this list so they stay', () => {
        assert.deepEqual(focusFor({ navigating: true, streetView: false }), { entrances: false, streetViewLayers: false });
    });

    test('street view pressed during navigation brings the highlights back, room dots stay hidden', () => {
        assert.deepEqual(focusFor({ navigating: true, streetView: true }), { entrances: false, streetViewLayers: true });
    });

    test('after End or arrival everything comes back, without street view', () => {
        assert.deepEqual(focusFor({ navigating: false, streetView: false }), { entrances: true, streetViewLayers: false });
    });
});

describe('reduced motion turns the effects off', () => {
    test('with prefers-reduced-motion nothing moves', () => {
        assert.deepEqual(motionFor({ reducedMotion: true, hidden: false, viewerOpen: false }), { flow: false, pulse: false, pin: false, glide: false, drawIn: false });
    });

    test('without it everything runs while the tab is visible and no photo is open', () => {
        assert.deepEqual(motionFor({ reducedMotion: false, hidden: false, viewerOpen: false }), { flow: true, pulse: true, pin: true, glide: true, drawIn: true });
    });

    test('the flow and pulse pause for a hidden tab or an open photo; the one-off moves still run', () => {
        assert.deepEqual(motionFor({ reducedMotion: false, hidden: true, viewerOpen: false }), { flow: false, pulse: false, pin: true, glide: true, drawIn: true });
        assert.deepEqual(motionFor({ reducedMotion: false, hidden: false, viewerOpen: true }), { flow: false, pulse: false, pin: true, glide: true, drawIn: true });
    });
});

describe('the route and dot animations', () => {
    test('the draw-in grows from nothing to the whole line over DRAW_IN_MS', () => {
        assert.equal(drawInFraction(0), 0);
        assert.equal(drawInFraction(DRAW_IN_MS), 1);
        assert.equal(drawInFraction(DRAW_IN_MS * 3), 1);
        assert.ok(drawInFraction(100) > 0 && drawInFraction(100) < drawInFraction(200));
    });

    test('a partial line keeps the start and the given share of the length', () => {
        const line = [START, east(100)];
        assert.deepEqual(partialLines([line], 0), []);
        assert.deepEqual(partialLines([line], 1), [line]);
        const half = partialLines([line], 0.5);
        assert.equal(half.length, 1);
        assert.ok(Math.abs(length(half[0]) - 50) < 0.2, `${length(half[0])}`);
        assert.deepEqual(half[0][0], START);
    });

    test('a partial line spreads over several parts by their total length', () => {
        const parts = [[START, east(30)], [east(30), east(90)]];
        const cut = partialLines(parts, 0.5);
        assert.equal(cut.length, 2);
        // Half of 90 m is 45 m: all 30 m of the first part, then 15 m of the second.
        assert.ok(Math.abs(length(cut[0]) - 30) < 0.2 && Math.abs(length(cut[1]) - 15) < 0.2);
    });

    test('the glide goes from the old spot to the new one and ends there', () => {
        const from = START;
        const to = east(20);
        assert.deepEqual(glidePoint(from, to, 0), from);
        assert.deepEqual(glidePoint(from, to, GLIDE_MS), to);
        const middle = glidePoint(from, to, GLIDE_MS / 3);
        assert.ok(middle[0] > from[0] && middle[0] < to[0]);
    });

    test('the dashes move toward the end of the route, a full period every 16 px, and never more than one period off', () => {
        assert.equal(flowDashOffset(0), 0);
        assert.ok(Math.abs(flowDashOffset(1000) - -2) < 1e-9, `${flowDashOffset(1000)}`);
        for (const ms of [250, 999, 4000, 123456]) {
            const offset = flowDashOffset(ms);
            assert.ok(offset <= 0 && offset > -FLOW_PERIOD_PIXELS, `${ms}: ${offset}`);
        }
    });

    test('the flow path is SVG path data for the projected screen points', () => {
        assert.equal(pathData([[1, 2], [3, 4]]), 'M1.0 2.0 L3.0 4.0');
        assert.equal(pathData([]), '');
    });
});
