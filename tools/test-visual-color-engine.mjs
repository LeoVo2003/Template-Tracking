import assert from 'node:assert/strict';
import test from 'node:test';
import { brandEvidence, canvasFromFamilyCoverage, familyForOklch, rgbToOklch } from '../.github/scripts/visual/color-engine.mjs';

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

test('V3.18 role recurrence keeps a small repeated gold UI accent ahead of a giant dark canvas', () => {
  const sample = (color, role, section, control, area = 0.003) => ({ color, role, section_key: section, control_key: control, kind: 'background', area_ratio: area, opacity: 1 });
  const rows = brandEvidence([
    { ...sample('rgb(20,20,20)', 'section', 'hero', '', 0.72) },
    ...['hero', 'nav', 'menu', 'card-1', 'card-2', 'footer'].map((section, index) => sample('rgb(212,175,55)', index === 0 ? 'cta' : (index === 1 ? 'active_nav' : 'heading'), section, `gold-${index}`)),
  ]);
  assert.equal(rows[0].family, 'gold');
  assert.ok(rows[0].section_count >= 5);
});

test('photo-like area does not participate in brand scoring without a UI role', () => {
  const rows = brandEvidence([
    { color: 'rgb(245,150,185)', role: 'section', kind: 'background', area_ratio: 0.85, opacity: 1, section_key: 'photo', control_key: '' },
    { color: 'rgb(212,175,55)', role: 'cta', kind: 'background', area_ratio: 0.004, opacity: 1, section_key: 'hero', control_key: 'book' },
    { color: 'rgb(212,175,55)', role: 'heading', kind: 'text', area_ratio: 0.003, opacity: 1, section_key: 'menu', control_key: '' },
  ]);
  assert.equal(rows[0].family, 'gold');
});
