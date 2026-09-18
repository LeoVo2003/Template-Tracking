import assert from 'node:assert/strict';
import test from 'node:test';
import { batchCapacity, fullRunContinuationTargets, normalizeJobScope, parseTargetIds } from '../.github/scripts/visual/job-scope.mjs';

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

test('a full batch of ten continues exactly its ten successful fresh captures', () => {
  const captured = Array.from({ length: 10 }, (_, index) => index + 100);
  assert.deepEqual(fullRunContinuationTargets('full', captured, captured), captured);
});

test('a partial capture only continues successful IDs and never substitutes another queue item', () => {
  const captured = [100, 101, 102, 103, 104, 105, 106, 107];
  assert.deepEqual(fullRunContinuationTargets('full', captured, [...captured, 999]), captured);
  assert.deepEqual(fullRunContinuationTargets('capture', captured, captured), []);
});

test('invalid targeted IDs cannot be replaced by an implicit global batch', () => {
  assert.throws(() => normalizeJobScope({ run_mode: 'targeted', stage: 'tone', target_ids: '[]', limit: 4 }), /requires at least one target ID/);
	assert.throws(() => parseTargetIds('[1,"bad",0,2]'), /TARGET_IDS/);
});
