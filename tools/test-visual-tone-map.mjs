import assert from 'node:assert/strict';
import test from 'node:test';
import { mapVietnameseTone } from '../.github/scripts/visual/tone-map.mjs';

test('semantic families map to stable Vietnamese tones', () => {
  assert.equal(mapVietnameseTone({ primary_family: 'pink', secondary_family: 'brown', canvas_family: 'white' }), 'Hồng trắng');
  assert.equal(mapVietnameseTone({ primary_family: 'brown', secondary_family: 'neutral', canvas_family: 'cream' }), 'Nâu kem');
  assert.equal(mapVietnameseTone({ primary_family: 'orange', secondary_family: 'neutral', canvas_family: 'white' }), 'Cam trắng');
  assert.equal(mapVietnameseTone({ primary_family: 'teal', secondary_family: 'yellow_gold', canvas_family: 'white' }), 'Xanh vàng');
});
