import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

const main = await readFile('.github/scripts/visual/main.mjs', 'utf8');
const repository = await readFile('includes/class-mac-tracker-repository.php', 'utf8');
const admin = await readFile('includes/class-mac-tracker-admin.php', 'utf8');
const browserAdmin = await readFile('assets/admin.js', 'utf8');

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
  assert.match(reanalyze, /requeue_visual_analysis_targets\( \$ids \)/);
  assert.doesNotMatch(reanalyze, /capture_queued/);
  const targetsStart = repository.indexOf('public function requeue_visual_analysis_targets');
  const targets = repository.slice(targetsStart, repository.indexOf('public function recover_visual_analysis_dispatch', targetsStart));
  assert.match(targets, /screenshot_url <> ''/);
  assert.match(targets, /capture_bundle_json <> ''/);
  assert.match(targets, /manual_locked = 0/);
  assert.match(targets, /lease_until IS NULL OR lease_until <= UTC_TIMESTAMP\(\)/);
  assert.match(targets, /pipeline_status NOT IN \('capturing', 'analyzing'\)/);
  const allStoredStart = repository.indexOf('public function requeue_visual_tones()');
  const allStored = repository.slice(allStoredStart, repository.indexOf('public function requeue_visual_tones_by_labels', allStoredStart));
  assert.match(allStored, /capture_bundle_json <> ''/);
  assert.doesNotMatch(allStored, /pipeline_status IN \('captured', 'classified', 'needs_review'\)/);
});

test('an analysis dispatch failure recovers queued IDs instead of orphaning them', () => {
  const recoveryStart = repository.indexOf('public function recover_visual_analysis_dispatch');
  const recovery = repository.slice(recoveryStart, repository.indexOf('public function requeue_visual_tones_by_labels', recoveryStart));
  assert.match(recovery, /pipeline_status = 'retry_wait'/);
  assert.match(recovery, /last_error_code = 'GITHUB_DISPATCH_FAILED'/);
  assert.match(recovery, /WHERE pipeline_status = 'analysis_queued' AND project_id IN/);

  const actionStart = admin.indexOf('private function process_visual_action');
  const action = admin.slice(actionStart, admin.indexOf('public function handle_visual_action_ajax', actionStart));
  assert.match(action, /requeue_visual_tones_with_ids\(\)/);
  assert.match(action, /requeue_visual_analysis_targets\( \$ids \)/);
  assert.match(action, /recover_visual_analysis_dispatch\( \$recover, \$dispatch->get_error_message\(\) \)/);
  assert.match(action, /recover_visual_analysis_dispatch\( \$dispatch_ids, \$dispatch->get_error_message\(\) \)/);
  assert.match(action, /'dispatch_error' => is_wp_error\( \$dispatch \)/);
});

test('analysis queued uses one clear UI message and dispatch failures are surfaced', () => {
  assert.match(admin, /'analysis_queued'\s*=> array\( 'queued', 'dashicons-clock', 'Queued for AI analysis' \)/);
  assert.match(browserAdmin, /analysis_queued:\['queued','clock','Queued for AI analysis'\]/);
  assert.match(browserAdmin, /GITHUB_DISPATCH_FAILED/);
  assert.match(browserAdmin, /GitHub dispatch failed\./);
  assert.doesNotMatch(browserAdmin, /Analysis queued · chờ batch/);
});
