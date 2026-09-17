import assert from 'node:assert/strict';
import test from 'node:test';
import { familyForOklch, rgbToOklch } from '../.github/scripts/visual/color-engine.mjs';

const fromHex = (hex) => ({ r: Number.parseInt(hex.slice(1, 3), 16), g: Number.parseInt(hex.slice(3, 5), 16), b: Number.parseInt(hex.slice(5, 7), 16) });
const family = (hex) => familyForOklch(rgbToOklch(...Object.values(fromHex(hex))));

test('OKLCH calibration avoids catastrophic palette family mistakes', () => {
  assert.equal(family('#F2D7DC'), 'pink');
  assert.equal(family('#D9A4AF'), 'pink');
  assert.equal(family('#C98154'), 'orange');
  assert.equal(family('#D4AF37'), 'yellow_gold');
  assert.equal(family('#D8C6B3'), 'cream');
  assert.equal(family('#8A6E5A'), 'brown');
  assert.equal(family('#0F5C63'), 'teal');
});

test('boundary colours remain intentionally bounded rather than being treated as red by default', () => {
  assert.ok(['orange', 'pink'].includes(family('#E9C2B5')));
  assert.ok(['brown', 'orange'].includes(family('#B98050')));
  assert.ok(['yellow_gold', 'brown'].includes(family('#C7A76B')));
  assert.ok(['black', 'blue'].includes(family('#0E1A2B')));
});
