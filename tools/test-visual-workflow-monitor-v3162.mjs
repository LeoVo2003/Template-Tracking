import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');

test('V3.16.2 keeps collapsed monitor list and pagination hidden despite layout CSS', async () => {
  const [css, admin] = await Promise.all([read('assets/admin.css'), read('includes/class-mac-tracker-admin.php')]);
  assert.match(admin, /data-workflow-list[^>]*hidden/);
  assert.match(admin, /data-workflow-pagination[^>]*hidden/);
  assert.match(css, /\.mac-tracker-workflow-list\[hidden\]\s*\{[^}]*display:\s*none\s*!important/);
  assert.match(css, /\.mac-tracker-workflow-pagination\[hidden\]\s*\{[^}]*display:\s*none\s*!important/);
});

test('monitor uses one neutral status region and preserves actionable errors', async () => {
  const [css, admin, js] = await Promise.all([read('assets/admin.css'), read('includes/class-mac-tracker-admin.php'), read('assets/visual-workflow-monitor.js')]);
  assert.doesNotMatch(css, /mac-tracker-workflow-monitor__notice/);
  assert.doesNotMatch(admin, /mac-tracker-workflow-monitor__notice/);
  assert.doesNotMatch(js, /mac-tracker-workflow-monitor__notice|is-error/);
  assert.match(css, /mac-tracker-workflow-monitor__status/);
  assert.match(css, /is-actionable/);
  assert.match(js, /setNotice\(text, error\)/);
});

test('expand waits for AJAX pagination decision and never flashes controls', async () => {
  const js = await read('assets/visual-workflow-monitor.js');
  const handler = js.slice(js.indexOf("data-workflow-view-all"));
  assert.match(handler, /list\.hidden = !expanded/);
  assert.match(handler, /pagination\.hidden = true/);
  assert.doesNotMatch(handler, /pagination\.hidden = !expanded/);
  assert.match(js, /pagination\.hidden = !expanded \|\| pages <= 1/);
});

test('GitHub total_count is authoritative with Link header fallback only when absent', async () => {
  const github = await read('includes/class-mac-tracker-github-actions.php');
  assert.match(github, /array_key_exists\( 'total_count', \$data \)/);
  assert.match(github, /\$source_total = \$has_total_count/);
  assert.match(github, /absint\( \$data\['total_count'\] \)/);
  assert.match(github, /! \$has_total_count && preg_match/);
});

test('merged monitor pagination loads each source through the requested page and slices once', async () => {
  const github = await read('includes/class-mac-tracker-github-actions.php');
  assert.match(github, /for \( \$source_page = 1; \$source_page <= \$page; \$source_page\+\+ \)/);
  assert.match(github, /array_slice\( \$runs, \( \$page - 1 \) \* \$per_page, \$per_page \)/);
  assert.match(github, /array_sum\( \$workflow_totals \)/);
  assert.match(github, /\$seen_run_ids/);
  assert.match(github, /\$b\['id'\].*\$a\['id'\]/s);
});

test('monitor summary uses fresh GitHub runs and keeps stale history explicitly unknown', async () => {
  const [github, admin, js] = await Promise.all([read('includes/class-mac-tracker-github-actions.php'), read('includes/class-mac-tracker-admin.php'), read('assets/visual-workflow-monitor.js')]);
  assert.match(github, /fresh_summary\['scope'\] = .*github_fresh_page/);
  assert.match(admin, /'scope' => 'stale_unknown'/);
  assert.doesNotMatch(admin.slice(admin.indexOf('public function handle_visual_runs_ajax'), admin.indexOf('public function handle_visual_run_detail_ajax')), /visual_run_summary\(\)/);
  assert.match(js, /Current status from fresh GitHub runs/);
  assert.match(js, /stale local history only/);
});

test('local retry preflight requires an online windows + mac-visual runner', async () => {
  const [github, admin, js] = await Promise.all([read('includes/class-mac-tracker-github-actions.php'), read('includes/class-mac-tracker-admin.php'), read('assets/visual-workflow-monitor.js')]);
  assert.match(github, /actions\/runners\?per_page=100/);
  assert.match(github, /in_array\( 'windows', \$labels, true \)/);
  assert.match(github, /in_array\( 'mac-visual', \$labels, true \)/);
  assert.match(github, /LOCAL_RUNNER_NOT_READY/);
  const local = admin.slice(admin.indexOf("preg_match( '/^local_retry_one_"), admin.indexOf("preg_match( '/^(reanalyze_one", admin.indexOf("preg_match( '/^local_retry_one_")));
  assert.match(local, /preflight_local_runner\(\)/);
  assert.match(local, /queue_local_visual_retry/);
  assert.match(local, /dispatch_local/);
  assert.ok(local.indexOf('preflight_local_runner') < local.indexOf('queue_local_visual_retry'));
  assert.match(js, /waiting for a self-hosted runner with windows \+ mac-visual labels/);
});

test('runner preflight distinguishes zero, missing labels, matching online, and unknown states', async () => {
  const github = await read('includes/class-mac-tracker-github-actions.php');
  assert.match(github, /state' => 'no_matching'/);
  assert.match(github, /No matching self-hosted runner online \(%d runners\)/);
  assert.match(github, /'state' => \$busy === count\( \$matching \) \? 'busy' : 'online'/);
  assert.match(github, /state' => 'unknown'/);
  assert.match(github, /Runner status unavailable/);
  assert.match(github, /settings\/actions\/runners\/new/);
});
