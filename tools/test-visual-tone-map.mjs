import assert from 'node:assert/strict';
import test from 'node:test';
import { mapVietnameseTone, resolveTone } from '../.github/scripts/visual/tone-map.mjs';

const semantic = (primary_family, primary_surface, secondary_surface = '', extra = {}) => ({ primary_family, secondary_family: secondary_surface, canvas_mode: extra.canvas_mode || 'light', primary_surface, secondary_surface, ...extra });

test('V3.15 navy plus white is mixed, precise Navy trắng, and grouped Xanh trắng', () => {
  const result = resolveTone(semantic('navy', 'white', 'navy', { canvas_mode: 'mixed', light_surface_ratio: 0.53, dark_surface_ratio: 0.42 }));
  assert.deepEqual(result, { precise_tone: 'Navy trắng', tone_group: 'Xanh trắng', base_surface: 'white' });
  assert.notEqual(result.tone_group, 'Xanh đen');
});

test('V3.15 true dark navy remains Xanh đen', () => {
  const result = resolveTone(semantic('navy', 'navy', 'white', { canvas_mode: 'dark', light_surface_ratio: 0.18, dark_surface_ratio: 0.68 }));
  assert.equal(result.tone_group, 'Xanh đen');
});

test('neutral-first gray and greige templates no longer become Cần duyệt', () => {
  assert.deepEqual(resolveTone(semantic('gray', 'cream')), { precise_tone: 'Xám kem', tone_group: 'Xám kem', base_surface: 'cream' });
  assert.deepEqual(resolveTone(semantic('greige', 'cream')), { precise_tone: 'Greige kem', tone_group: 'Xám kem', base_surface: 'cream' });
  assert.equal(resolveTone(semantic('beige', 'cream')).tone_group, 'Be kem');
});

test('dark black, dusty rose, champagne, sage, teal and terracotta normalize predictably', () => {
  assert.equal(mapVietnameseTone(semantic('black', 'white')), 'Đen trắng');
  assert.equal(mapVietnameseTone(semantic('dusty_rose', 'white')), 'Hồng trắng');
  assert.equal(mapVietnameseTone(semantic('champagne', 'cream')), 'Vàng kem');
  assert.equal(mapVietnameseTone(semantic('sage', 'cream')), 'Xanh kem');
  assert.equal(mapVietnameseTone(semantic('teal', 'white')), 'Xanh trắng');
  assert.deepEqual(resolveTone(semantic('terracotta', 'cream')), { precise_tone: 'Cam đất kem', tone_group: 'Cam kem', base_surface: 'cream' });
});

test('V3.18 always resolves brand first and structural canvas second', () => {
  assert.equal(mapVietnameseTone(semantic('gold', 'black', 'white', { canvas_mode: 'dark' })), 'Vàng đen');
  assert.equal(mapVietnameseTone(semantic('pink', 'cream')), 'Hồng kem');
  assert.equal(mapVietnameseTone(semantic('brown', 'cream')), 'Nâu kem');
  assert.equal(mapVietnameseTone(semantic('blue', 'white')), 'Xanh trắng');
  assert.equal(mapVietnameseTone(semantic('black', 'white')), 'Đen trắng');
});
