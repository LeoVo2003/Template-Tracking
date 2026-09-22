import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { classifyOverlayEvidence } from '../.github/scripts/visual/overlay-policy.mjs';
import { normalizeJobScope, batchCapacity } from '../.github/scripts/visual/job-scope.mjs';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');

test('overlay policy accepts verified modal families and residue', () => {
  const cases = [
    { type: 'elementor_popup', visible: true, fixed: true, coverage: 0.72 },
    { type: 'bootstrap_modal', visible: true, fixed: true, coverage: 0.45, has_close_control: true },
    { type: 'pum_popup', visible: true, fixed: true, coverage: 0.52 },
    { type: 'aria_modal', visible: true, aria_modal: true, coverage: 0.2 },
    { type: 'modal_backdrop', visible: true, fixed: true, coverage: 1 },
  ];
  cases.forEach((descriptor) => assert.equal(classifyOverlayEvidence(descriptor).obstructive, true));
});
test('overlay policy preserves sticky headers, floating CTAs, heroes and galleries', () => {
  const cases = [
    { type: 'generic_overlay', visible: true, sticky: true, coverage: 0.12, z_index: 9999 },
    { type: 'generic_overlay', visible: true, fixed: true, coverage: 0.02, z_index: 9999, has_close_control: false },
    { type: 'generic_overlay', visible: true, fixed: false, coverage: 0.65, z_index: 0 },
    { type: 'generic_overlay', visible: true, fixed: false, coverage: 0.4, z_index: 0, has_close_control: false },
  ];
  cases.forEach((descriptor) => assert.equal(classifyOverlayEvidence(descriptor).obstructive, false));
});

test('capture validates first, then cleans overlays before UI sampling and screenshot', async () => {
  const capture = await read('.github/scripts/visual/capture.mjs');
  const worker = capture.slice(capture.indexOf('export async function captureRenderedPage'));
  assert.ok(worker.indexOf('resolveHomepage(page, requestedUrl)') < worker.indexOf('activateLazyContent(page)'));
  assert.ok(worker.indexOf('stabilize(page)') < worker.indexOf('dismissObstructiveOverlays(page)'));
  assert.ok(worker.indexOf('dismissObstructiveOverlays(page)') < worker.indexOf('collectUiSamples(page)'));
  assert.ok(worker.indexOf('collectUiSamples(page)') < worker.indexOf('page.screenshot({ fullPage: true'));
  assert.match(capture, /overlay_cleanup: overlayCleanup/);
  assert.match(capture, /element\.style\.removeProperty\('overflow'\)/);
  assert.match(capture, /overflow-y', 'auto', 'important'/);
});

test('AUTO uses four serialized runs per hour and eleven logical websites', async () => {
  const workflow = await read('.github/workflows/capture-visual-tone.yml');
  assert.match(workflow, /cron:\s*"2,17,32,47 \* \* \* \*"/);
  assert.match(workflow, /default:\s*"11"/);
  assert.match(workflow, /BATCH_LIMIT:\s*\$\{\{ inputs\.limit \|\| '11' \}\}/);
  assert.match(workflow, /group:\s*mac-project-tracker-visual-tone/);
  assert.match(workflow, /cancel-in-progress:\s*false/);
  assert.equal(normalizeJobScope({}).limit, 11);
  assert.deepEqual(batchCapacity(undefined, 0), { total: 11, tone: 0, capture: 11 });
});

test('workflow monitor separates active batches from rolling project throughput', async () => {
  const [repository, admin, js, github] = await Promise.all([
    read('includes/class-mac-tracker-repository.php'),
    read('includes/class-mac-tracker-admin.php'),
    read('assets/visual-workflow-monitor.js'),
    read('includes/class-mac-tracker-github-actions.php'),
  ]);
  assert.match(repository, /function visual_run_throughput_summary/);
  assert.match(repository, /GROUP BY github_run_id/);
  assert.match(repository, /SUM\(success_count\).*SUM\(needs_review_count\).*SUM\(failed_count\)/s);
  assert.match(repository, /INTERVAL 60 MINUTE/);
  assert.match(github, /running_batches/);
  assert.match(github, /queued_batches/);
  for (const metric of ['running_batches', 'queued_batches', 'success_projects', 'needs_review_projects', 'failed_projects']) {
    assert.match(admin, new RegExp(`data-workflow-metric="${metric}"`));
    assert.match(js, new RegExp(metric));
  }
  assert.doesNotMatch(admin, />Completed <strong>/);
});

test('Projects tone filter uses normalized brand parents and simple surface children', async () => {
  const [repository, admin] = await Promise.all([
    read('includes/class-mac-tracker-repository.php'),
    read('includes/class-mac-tracker-admin.php'),
  ]);
  assert.match(repository, /function normalize_visual_tone_for_filter/);
  assert.match(repository, /function visual_tone_filter_groups/);
  assert.match(repository, /function visual_tones_for_filter/);
  for (const mapping of ["'Vàng' => 'yellow'", "'Đỏ' => 'red'", "'Hồng' => 'pink'", "'Xanh' => 'blue'", "'Tím' => 'purple'", "'Cam' => 'orange'"]) assert.match(repository, new RegExp(mapping.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  assert.match(repository, /'đen' => 'black'.*'xám' => 'gray'.*'kem' => 'cream'.*'be' => 'cream'.*'trắng' => 'white'/s);
  assert.match(repository, /v\.tone IN \(' .* array_fill\( 0, count\( \$matching_tones \), '%s' \)/);
  assert.match(repository, /in_array\( \$tone, \$allowed_tones, true \)/);
  assert.match(admin, /<optgroup label=/);
  assert.match(admin, /all variants/);
  assert.doesNotMatch(admin, /foreach \( \$this->tone_options\(\) as \$tone \)/);
});
