// The mobile app (CNAS-mobile, metro.config.js) imports these navigation modules straight from this folder, so the
// phone computes the same routes as /navigate. They must load without a DOM: no MapLibre, no CSS, no window or
// document at import time, and no imports from outside this folder except single Turf modules.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';

export const SHARED_MODULES = [
    'constants',
    'evacuation',
    'geo',
    'gps',
    'graph',
    'incident',
    'instructions',
    'legs',
    'motion',
    'position',
    'route-panoramas',
    'router',
    'simulate',
    'steps',
    'storage',
    'tracker',
    'voice',
];

const folder = new URL('../../resources/js/navigation/', import.meta.url);
const importsOf = (name) =>
    [...readFileSync(new URL(`${name}.js`, folder), 'utf8').matchAll(/^\s*(?:import|export)\s[^'"]*?from\s+['"]([^'"]+)['"]|^\s*import\s+['"]([^'"]+)['"]/gm)].map(
        (match) => match[1] ?? match[2],
    );

describe('navigation modules shared with the mobile app', () => {
    test('import only each other and single Turf modules', () => {
        for (const name of SHARED_MODULES) {
            for (const specifier of importsOf(name)) {
                const local = specifier.match(/^\.\/([\w-]+)\.js$/);
                const allowed = local ? SHARED_MODULES.includes(local[1]) : /^@turf\/[\w-]+$/.test(specifier) && specifier !== '@turf/turf';
                assert.ok(allowed, `${name}.js imports "${specifier}", which the mobile app can't load`);
            }
        }
    });

    test('load without a DOM', async () => {
        assert.equal(typeof globalThis.window, 'undefined');
        assert.equal(typeof globalThis.document, 'undefined');
        for (const name of SHARED_MODULES) {
            await assert.doesNotReject(import(new URL(`${name}.js`, folder)), `${name}.js failed to load without a DOM`);
        }
    });
});
