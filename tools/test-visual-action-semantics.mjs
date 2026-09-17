import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

const main = await readFile('.github/scripts/visual/main.mjs', 'utf8');
const repository = await readFile('includes/class-mac-tracker-repository.php', 'utf8');

test('analysis-only worker path does not invoke capture processing', () => {
  assert.match(main, /processToneItems\(jobs\.filter\(\(item\) => item\.stage === 'tone'\)/);
  assert.match(main, /scope\.run_mode === 'batch' \|\| scope\.stage === 'full'/);
});

test('capture-only path stops before AI unless an explicit full stage is requested', () => {
  assert.match(main, /capturedIds\.length && \(scope\.run_mode === 'batch' \|\| scope\.stage === 'full'\)/);
});

test('repository persists failed stage and separates capture from analysis retries', () => {
  assert.match(repository, /last_failed_stage/);
  assert.match(repository, /requeue_failed_visual_items_by_stage/);
  assert.match(repository, /pipeline_status = 'analysis_queued'/);
  assert.match(repository, /pipeline_status = 'capture_queued'/);
});
