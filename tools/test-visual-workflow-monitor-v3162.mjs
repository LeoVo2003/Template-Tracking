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
