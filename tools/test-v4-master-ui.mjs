import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [admin, repository, css, ui, actions, workflow] = await Promise.all([
  readFile('includes/class-mac-tracker-admin.php', 'utf8'),
  readFile('includes/class-mac-tracker-repository.php', 'utf8'),
  readFile('assets/standalone.css', 'utf8'),
  readFile('assets/app-ui.js', 'utf8'),
  readFile('assets/admin-actions.js', 'utf8'),
  readFile('assets/visual-workflow-monitor.js', 'utf8'),
]);

test('V4 theme system is persisted, scoped and previewed without a reload', () => {
  assert.match(admin, /mac_tracker_ui_theme/);
  assert.match(admin, /handle_save_ui_theme_ajax/);
  for (const theme of ['warm-ivory', 'champagne', 'fresh-botanical', 'quiet-olive']) {
    assert.match(admin, new RegExp(`'${theme}'`));
    assert.match(css, new RegExp(`data-mac-theme=\\"${theme}`));
  }
  assert.match(ui, /data-mac-theme-save/);
  assert.match(ui, /setTheme\(/);
  assert.doesNotMatch(ui, /location\.reload\(\)/);
});

test('V4 projects present grouped template filters and bounded All-mode loading', () => {
  assert.match(repository, /function list_layout_groups\(\)/);
  assert.match(repository, /demo:s/);
  assert.match(repository, /LOWER\(p\.layout_url\) REGEXP/);
  assert.match(admin, /<optgroup label="Templates">/);
  assert.match(admin, /External layout/);
  assert.match(admin, /'per_page'[^\n]+100/);
  assert.match(admin, /array\( 25, 50, 100, 200 \)/);
  assert.match(admin, /wp_ajax_mac_tracker_load_project_rows/);
  assert.match(admin, /check_ajax_referer\( 'mac_tracker_load_project_rows'/);
  assert.match(admin, /data-mac-project-lazy-load/);
  assert.match(ui, /hydrateProjectRows/);
  assert.match(ui, /mac_tracker_load_project_rows/);
  assert.match(ui, /Load next 100/);
});

test('V4 AI transitions are authenticated, cancellable fragments with cache and local error states', () => {
  assert.match(admin, /wp_ajax_mac_tracker_load_fragment/);
  assert.match(admin, /check_ajax_referer\( 'mac_tracker_load_fragment'/);
  assert.match(admin, /current_user_can\( 'manage_options' \)/);
  assert.match(admin, /data-mac-fragment-target="ai-panel"/);
  assert.match(ui, /fragmentCache/);
  assert.match(ui, /AbortController/);
  assert.match(ui, /mac-tracker-fragment-skeleton/);
  assert.match(ui, /mac-tracker-fragment-error/);
  assert.match(actions, /mac:invalidatefragments/);
});

test('V4 heavy card surfaces use a logical 100-item page with deferred DOM chunks', () => {
  assert.match(repository, /function color_review_page[\s\S]*?\$per_page = in_array/);
  assert.match(repository, /function visual_review_page[\s\S]*?\$per_page = in_array/);
  assert.match(admin, /array_slice\( \$page\['rows'\], 0, 24 \)/);
  assert.match(admin, /data-mac-lazy-cards/);
  assert.match(ui, /data-mac-lazy-load/);
  assert.match(ui, /slice\(0, 24\)/);
  assert.match(admin, /presentation_page_request/);
  assert.match(admin, /data-mac-fragment-page/);
});

test('V4 processing and Settings both use canonical observability', () => {
  assert.match(admin, /\$this->repository->visual_automation_observability\(\)/);
  assert.match(admin, /Advanced controls/);
  assert.match(admin, /Human approved/);
  assert.match(admin, /Capture blocked/);
  assert.match(workflow, /perPage = 100/);
});

test('V4 Workflow Log returns a real 100-run logical page instead of silently truncating it', async () => {
  const github = await readFile('includes/class-mac-tracker-github-actions.php', 'utf8');
  assert.match(github, /\$per_page = in_array\( absint\( \$per_page \), array\( 25, 50, 100 \), true \) \? absint\( \$per_page \) : 100/);
  assert.match(github, /\$api_per_page = 100/);
  assert.match(github, /\$source_pages_required = max\( 1, \(int\) ceil\( \( \$page \* \$per_page \) \/ \$api_per_page \) \)/);
  assert.match(github, /array_slice\( \$runs, \( \$page - 1 \) \* \$per_page, \$per_page \)/);
  assert.match(github, /array\( 25, 50, 100 \) as \$per_page/);
});
