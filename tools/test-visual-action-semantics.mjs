import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

const main = await readFile('.github/scripts/visual/main.mjs', 'utf8');
const repository = await readFile('includes/class-mac-tracker-repository.php', 'utf8');

test('analysis-only worker path does not invoke capture processing', () => {
  assert.match(main, /processToneItems\(jobs\.filter\(\(item\) => item\.stage === 'tone'\)/);
  assert.match(main, /scope\.run_mode === 'batch' \|\| scope\.stage === 'full'/);
});

test('a tone job receives its Gemini budget explicitly without out-of-scope config', () => {
  const start = main.indexOf('async function processToneItems');
  const end = main.indexOf('async function writeSummary', start);
  const body = main.slice(start, end);
  assert.match(body, /processToneItems\(items, aiStrategy, autoAccept, geminiDailyBudgetPerKey\)/);
  assert.match(body, /geminiDailyBudgetPerKey,/);
  assert.doesNotMatch(body, /config\./);
  assert.match(main, /processToneItems\(jobs\.filter[\s\S]*geminiDailyBudgetPerKey\)/);
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

test('analysis eligibility is bundle-based, recapture-free, and protects live/manual records', () => {
  const reanalyzeStart = repository.indexOf("if ( 'reanalyze' === $mode )");
  const reanalyze = repository.slice(reanalyzeStart, repository.indexOf("} elseif ( 'recapture'", reanalyzeStart));
  assert.match(reanalyze, /screenshot_url <> ''/);
  assert.match(reanalyze, /capture_bundle_json <> ''/);
  assert.match(reanalyze, /manual_locked = 0/);
  assert.match(reanalyze, /lease_until IS NULL OR lease_until <= UTC_TIMESTAMP\(\)/);
  assert.match(reanalyze, /pipeline_status NOT IN \('capturing', 'analyzing'\)/);
  assert.doesNotMatch(reanalyze, /capture_queued/);
  const allStoredStart = repository.indexOf('public function requeue_visual_tones()');
  const allStored = repository.slice(allStoredStart, repository.indexOf('public function requeue_visual_tones_by_labels', allStoredStart));
  assert.match(allStored, /capture_bundle_json <> ''/);
  assert.doesNotMatch(allStored, /pipeline_status IN \('captured', 'classified', 'needs_review'\)/);
});
