import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [admin, repository, app, css, ui] = await Promise.all([
  readFile('includes/class-mac-tracker-admin.php', 'utf8'),
  readFile('includes/class-mac-tracker-repository.php', 'utf8'),
  readFile('includes/class-mac-tracker-app.php', 'utf8'),
  readFile('assets/standalone.css', 'utf8'),
  readFile('assets/app-ui.js', 'utf8'),
]);

test('production app remains WordPress-authenticated and full-canvas', () => {
  assert.match(app, /auth_redirect\(\)/);
  assert.match(app, /current_user_can\( 'manage_options' \)/);
  assert.match(app, /set_public_view\( false \)/);
  assert.match(css, /Full application canvas/);
  assert.doesNotMatch(css, /\.mac-tracker-app-shell\s*\{[^}]*margin:\s*\d+px\s+auto/);
});

test('project filters use real layouts, AI tone and exact date ranges', () => {
  assert.match(repository, /function list_layouts\(\)/);
  assert.match(repository, /SELECT DISTINCT p\.layout_url/);
  assert.match(repository, /bangkok_day_start_utc/);
  assert.match(repository, /bangkok_next_day_start_utc/);
  assert.match(admin, /<span>AI tone<\/span>/);
  assert.match(admin, /<span>Layout<\/span>/);
  assert.doesNotMatch(admin, /<span>Website<\/span><select name="website"/);
});

test('review counts are aggregate queries and both review surfaces are paginated', () => {
  assert.match(repository, /function color_review_counts\(\)/);
  assert.match(repository, /function visual_review_counts\(\)/);
  assert.match(repository, /function color_review_page[\s\S]*?LIMIT %d OFFSET %d/);
  assert.match(repository, /function visual_review_page[\s\S]*?LIMIT %d OFFSET %d/);
  assert.match(admin, /color_review_page\( \$status, \$page_number, \$per_page, \$search \)/);
  assert.match(admin, /visual_review_page\( \$section, \$page_number, \$per_page \)/);
});

test('topbar, sorting and row actions use the rebuilt presentation layer', () => {
  assert.doesNotMatch(admin, /notification-bell|dashicons-bell/);
  assert.match(admin, /class="mac-tracker-sort/);
  assert.match(admin, /<svg aria-hidden="true" viewBox="0 0 12 12"/);
  assert.match(admin, /data-mac-menu-trigger/);
  assert.match(admin, /data-mac-menu hidden/);
  assert.match(ui, /data-edit-target/);
  assert.match(ui, /Escape/);
});

test('settings and AI use a single-level tab architecture', () => {
  for (const section of ['action', 'processing', 'review', 'locked', 'workflow']) {
    assert.match(admin, new RegExp(`'section' => '${section}'`));
  }
  for (const setting of ['appearance', 'import', 'connections', 'automation', 'data']) {
    assert.match(admin, new RegExp(`data-settings-tab="${setting}"`));
  }
  assert.match(admin, /color_status' => 'pending'/);
  assert.match(admin, /color_status' => 'approved'/);
});
