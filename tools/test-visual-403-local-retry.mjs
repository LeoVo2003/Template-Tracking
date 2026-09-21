import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

const [main, capture, service, repository, admin, workflow, color, tone] = await Promise.all([
  readFile('.github/scripts/visual/main.mjs', 'utf8'),
  readFile('.github/scripts/visual/capture.mjs', 'utf8'),
  readFile('includes/class-mac-tracker-visual-service.php', 'utf8'),
  readFile('includes/class-mac-tracker-repository.php', 'utf8'),
  readFile('includes/class-mac-tracker-admin.php', 'utf8'),
  readFile('.github/workflows/capture-visual-tone-local.yml', 'utf8'),
  readFile('.github/scripts/visual/color-engine.mjs', 'utf8'),
  readFile('.github/scripts/visual/tone-map.mjs', 'utf8'),
]);

test('403 diagnostics are multipart-only and cannot become the AI screenshot', () => {
  assert.match(capture, /diagnostic_screenshot/);
  assert.match(main, /mode', 'diagnostic'/);
  assert.match(main, /uploadDiagnostic/);
  assert.match(service, /'diagnostic' === \$mode/);
  assert.match(repository, /diagnostic_screenshot_url/);
  assert.match(repository, /screenshot_url <> '' OR v\.diagnostic_screenshot_url/);
  assert.match(main, /if \('capture_failed' === mode\) await uploadDiagnostic/);
});

test('a successful local capture clears stale security retry state but keeps diagnostic history', () => {
  assert.match(repository, /pipeline_status' => 'captured'/);
  assert.match(repository, /screenshot_url' => esc_url_raw\( \$url \)/);
  assert.match(repository, /last_error_code' => ''/);
  assert.match(repository, /last_error_message' => null/);
  assert.match(repository, /runner_type' => ''/);
  assert.match(repository, /diagnostic_screenshot_url/);
  assert.match(repository, /diagnostic_attachment_id/);
});

test('local retry is exact-target, self-hosted, and dispatch failure remains locally retryable', () => {
  assert.match(repository, /queue_local_visual_retry/);
  assert.match(repository, /manual_locked = 0 AND human_locked = 0/);
  assert.match(repository, /last_error_code IN/);
  assert.match(repository, /pipeline_status = 'retry_wait'/);
  assert.match(repository, /runner_type = 'local'/);
  assert.match(repository, /Local runner dispatch failed/);
  assert.match(admin, /local_retry_one_/);
  assert.match(admin, /dispatch_local/);
  assert.match(workflow, /self-hosted, windows, mac-visual/);
  assert.match(workflow, /TARGET_IDS/);
});

test('manual and human locks cannot expose local retry; Retry failed skips local security rows', () => {
  assert.match(admin, /empty\( \$row\['manual_locked'\] \)/);
  assert.match(admin, /! \$is_locked/);
  assert.match(repository, /'local' === \(string\) \$row\['runner_type'\]/);
  assert.match(repository, /HTTP_403/);
});

test('dark structural canvas remains generic and tone resolver keeps gold-first semantics', () => {
  assert.match(color, /dark_structural_dominance/);
  assert.match(color, /localSurface/);
  assert.match(tone, /gold.*black|black.*gold/s);
  assert.doesNotMatch(repository, /Magic Nails|Candy Nails|magic-nails|candy-nails/i);
});
