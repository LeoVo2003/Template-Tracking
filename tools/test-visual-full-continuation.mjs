import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

const main = await readFile('.github/scripts/visual/main.mjs', 'utf8');
const repository = await readFile('includes/class-mac-tracker-repository.php', 'utf8');
const service = await readFile('includes/class-mac-tracker-visual-service.php', 'utf8');

test('a full batch promotes and analyzes exactly its successful captures', () => {
  assert.match(main, /async function promoteFreshCaptures\(capturedIds\)/);
  assert.match(main, /capturedIds\.length && scope\.stage === 'full'/);
  assert.match(main, /const promotedIds = await promoteFreshCaptures\(capturedIds\)/);
  assert.match(main, /fullRunContinuationTargets\(scope\.stage, capturedIds, promotedIds\)/);
  assert.match(main, /target_ids: freshToneIds, limit: freshToneIds\.length/);
  assert.match(main, /await processToneItems\(freshToneJobs,/);
});

test('the tone continuation is excluded from the logical batch summary', () => {
  const claimStart = main.indexOf('async function claimJobs');
  const claim = main.slice(claimStart, main.indexOf('async function promoteFreshCaptures', claimStart));
  assert.match(claim, /if \(!continuation\)/);
  assert.match(main, /\{ continuation: true \}/);
  assert.match(main, /Claimed logical websites: \$\{summary\.claimed\}/);
});

test('fresh-capture promotion is exact, safe, and cannot drain the shared tone queue', () => {
  const start = repository.indexOf('public function promote_captured_visuals_for_full_analysis');
  const promote = repository.slice(start, repository.indexOf('public function visual_claim_jobs', start));
  assert.match(promote, /project_id IN/);
  assert.match(promote, /pipeline_status = 'captured'/);
  assert.match(promote, /pipeline_status = 'analysis_queued'/);
  assert.match(promote, /capture_status = 'captured'/);
  assert.match(promote, /screenshot_url <> ''/);
  assert.match(promote, /capture_bundle_json <> ''/);
  assert.match(promote, /manual_locked = 0/);
  assert.match(promote, /GET_LOCK/);
  assert.doesNotMatch(promote, /visual_queue\(/);
});

test('the private promotion endpoint accepts only the successful IDs supplied by the worker', () => {
  assert.match(service, /\/visual\/jobs\/promote-captures/);
  const start = service.indexOf('public function promote_captures');
  const endpoint = service.slice(start, service.indexOf('public function ingest', start));
  assert.match(endpoint, /promote_captured_visuals_for_full_analysis\( \$target_ids \)/);
  assert.match(endpoint, /At least one fresh capture ID is required/);
});

test('capture-only cannot enter the full-run analysis continuation', () => {
  const continuationStart = main.indexOf('if (capturedIds.length && scope.stage === \'full\')');
  const continuation = main.slice(continuationStart, main.indexOf('\n    }', continuationStart) + 6);
  assert.match(continuation, /promoteFreshCaptures/);
  assert.doesNotMatch(main, /scope\.run_mode === 'batch' \|\| scope\.stage === 'full'/);
});
