import assert from 'node:assert/strict';
import test from 'node:test';
import { canvasFromFamilyCoverage, familyForOklch, rgbToOklch } from '../.github/scripts/visual/color-engine.mjs';

const fromHex = (hex) => ({ r: Number.parseInt(hex.slice(1, 3), 16), g: Number.parseInt(hex.slice(3, 5), 16), b: Number.parseInt(hex.slice(5, 7), 16) });
const family = (hex) => familyForOklch(rgbToOklch(...Object.values(fromHex(hex))));

test('OKLCH calibration avoids catastrophic palette family mistakes', () => {
  assert.equal(family('#F2D7DC'), 'pink');
  assert.equal(family('#D9A4AF'), 'pink');
  assert.equal(family('#C98154'), 'orange');
  assert.equal(family('#D4AF37'), 'gold');
  assert.equal(family('#D8C6B3'), 'cream');
  assert.equal(family('#8A6E5A'), 'brown');
  assert.equal(family('#0F5C63'), 'teal');
});

test('boundary colours remain intentionally bounded rather than being treated as red by default', () => {
  assert.ok(['orange', 'pink', 'peach', 'dusty_rose'].includes(family('#E9C2B5')));
  assert.ok(['brown', 'orange'].includes(family('#B98050')));
  assert.ok(['gold', 'champagne', 'brown'].includes(family('#C7A76B')));
  assert.equal(family('#0E1A2B'), 'navy');
});

test('V3.15 keeps dark chromatic navy distinct from black and expands neutral families', () => {
  assert.equal(family('#111111'), 'black');
  assert.equal(family('#252525'), 'charcoal');
  assert.equal(family('#153246'), 'navy');
  assert.equal(family('#1C3D50'), 'navy');
  assert.equal(family('#223F50'), 'navy');
  assert.equal(family('#F8F4EA'), 'ivory');
  assert.equal(family('#E7DDCF'), 'cream');
  assert.equal(family('#B8B2A7'), 'greige');
  assert.equal(family('#D6C7A9'), 'beige');
});

test('canvas detector keeps a white/navy structural page mixed rather than black', () => {
  const result = canvasFromFamilyCoverage({ white: 0.54, navy: 0.41, dusty_rose: 0.05 });
  assert.equal(result.mode, 'mixed');
  assert.equal(result.primary_surface, 'white');
  assert.equal(result.secondary_surface, 'navy');
});
