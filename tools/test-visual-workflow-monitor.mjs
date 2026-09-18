import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');

test('V3.16 uses local heartbeat telemetry and no iframe/log scraping', async () => {
  const [service, worker, admin] = await Promise.all([
    read('includes/class-mac-tracker-visual-service.php'), read('.github/scripts/visual/main.mjs'), read('includes/class-mac-tracker-admin.php'),
  ]);
  assert.match(service, /\/visual\/run-heartbeat/);
  assert.match(service, /\/visual\/run-complete/);
  assert.match(worker, /reportRunHeartbeat/);
  assert.match(worker, /Workflow heartbeat ignored/);
  assert.match(worker, /GITHUB_RUN_ID/);
  assert.match(admin, /render_visual_workflow_monitor/);
  assert.doesNotMatch(admin, /<iframe/i);
});

test('V3.16 limits GitHub controls to the Visual Tone workflow server-side', async () => {
  const source = await read('includes/class-mac-tracker-github-actions.php');
  assert.match(source, /capture-visual-tone\.yml/);
  assert.match(source, /is_visual_run/);
  assert.match(source, /get_visual_run\( \$run_id, true \)/);
  assert.match(source, /rerun-failed-jobs/);
  assert.match(source, /\/cancel/);
  assert.doesNotMatch(source, /wp_localize_script/);
});

test('V3.16 monitor polls only while active and keeps secrets out of browser payloads', async () => {
  const [js, admin] = await Promise.all([read('assets/visual-workflow-monitor.js'), read('includes/class-mac-tracker-admin.php')]);
  assert.match(js, /if \(active\) timer = setTimeout/);
  assert.match(js, /6500/);
  assert.match(js, /mac_tracker_visual_run_action/);
  assert.doesNotMatch(js, /github_dispatch_token|Authorization|Bearer /);
  assert.doesNotMatch(admin, /'githubToken'/);
});

test('V3.16 presents real source actions and separates GitHub rerun from item retry', async () => {
  const js = await read('assets/visual-workflow-monitor.js');
  for (const action of ['analyze_selected', 'capture_selected_again', 'retry_failed_capture', 'retry_failed_analysis', 'analyze_all_stored', 'run_batch_now', 'scheduled_auto']) assert.match(js, new RegExp(action));
  assert.match(js, /Re-run failed GitHub jobs/);
  assert.match(js, /not the same as Visual Tone Retry failed/);
});
