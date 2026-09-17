import assert from 'node:assert/strict';
import test from 'node:test';
import { batchCapacity, normalizeJobScope, parseTargetIds } from '../.github/scripts/visual/job-scope.mjs';

test('Analyze selected keeps the exact four IDs and only claims tone work', () => {
  assert.deepEqual(normalizeJobScope({ run_mode: 'targeted', stage: 'tone', target_ids: '[5140,5139,5136,5134]', limit: '10' }), {
    run_mode: 'targeted', stage: 'tone', target_ids: [5140, 5139, 5136, 5134], limit: 4,
  });
});

test('Capture selected keeps the exact two IDs and only claims capture work', () => {
  assert.deepEqual(normalizeJobScope({ run_mode: 'targeted', stage: 'capture', target_ids: [7, 9], limit: 2 }), {
    run_mode: 'targeted', stage: 'capture', target_ids: [7, 9], limit: 2,
  });
});

test('batch capacity counts logical sites rather than one limit per stage', () => {
  assert.deepEqual(batchCapacity(10, 4), { total: 10, tone: 4, capture: 6 });
  assert.deepEqual(batchCapacity(10, 14), { total: 10, tone: 10, capture: 0 });
});

test('invalid targeted IDs cannot be replaced by an implicit global batch', () => {
  assert.throws(() => normalizeJobScope({ run_mode: 'targeted', stage: 'tone', target_ids: '[]', limit: 4 }), /requires at least one target ID/);
	assert.throws(() => parseTargetIds('[1,"bad",0,2]'), /TARGET_IDS/);
});
