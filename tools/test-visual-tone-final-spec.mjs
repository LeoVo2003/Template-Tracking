import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const repo = fs.readFileSync('includes/class-mac-tracker-repository.php', 'utf8');
const admin = fs.readFileSync('includes/class-mac-tracker-admin.php', 'utf8');
const activator = fs.readFileSync('includes/class-mac-tracker-activator.php', 'utf8');
const css = fs.readFileSync('assets/standalone.css', 'utf8');
const js = fs.readFileSync('assets/admin-actions.js', 'utf8');
const ui = fs.readFileSync('assets/app-ui.js', 'utf8');

test('canonical palette is the single source for taxonomy and contains regression tones', () => {
  assert.match(repo, /function visual_tone_palette/);
  assert.match(repo, /return array_keys\( \$this->visual_tone_palette\(\) \)/);
  for (const tone of ['Vàng kem', 'Xám kem', 'Xanh trắng', 'Vàng đen', 'Cần duyệt']) assert.match(repo, new RegExp(`['"]${tone.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}['"]`));
  assert.doesNotMatch(css, /\.mac-tracker-tone--vang-kem\s*\{/);
});

test('approval schema and exclusion registry are persistent', () => {
  for (const field of ['human_locked', 'approved_by', 'approved_at', 'approved_capture_revision']) assert.match(activator, new RegExp(field));
  assert.match(activator, /mac_tracker_project_exclusions/);
  assert.match(repo, /PROJECT_EXCLUDED/);
  assert.match(repo, /is_project_excluded/);
});

test('review flow exposes one-level Processing, Review and Locked routes and combined action', () => {
  assert.match(admin, /'section' => 'processing'/);
  assert.match(admin, /'section' => 'review'/);
  assert.match(admin, /'section' => 'locked'/);
  assert.match(admin, /capture_analyze_selected/);
  assert.match(repo, /capture_and_analyze_selected/);
  assert.match(admin, /Save &amp; approve/);
  assert.match(admin, /Approve/);
  assert.match(admin, /Bỏ qua project/);
  assert.doesNotMatch(admin, />Save controls<\/button>/);
});

test('controls auto-save and monitor hidden state remain guarded', () => {
  assert.match(admin, /mac_tracker_save_visual_controls/);
  assert.match(js, /Saving…/);
  assert.match(css, /\.mac-tracker-workflow-list\[hidden\][^{]*\{[^}]*display:\s*none\s*!important/);
  assert.match(css, /\.mac-tracker-workflow-pagination\[hidden\][^{]*\{[^}]*display:\s*none\s*!important/);
});

test('bulk actions stay scoped to the rendered page and locked cards remain immutable', () => {
  assert.doesNotMatch(admin, /value="reanalyze_all"/);
  for (const action of ['reanalyze_selected', 'recapture_selected', 'capture_analyze_selected']) assert.match(admin, new RegExp(`value="${action}" data-visual-bulk`));
  assert.match(js, /boxes\(\)\.forEach\(function \(box\) \{ box\.checked = true; \}\)/);
  assert.match(admin, /if \( 'locked' !== \$section \)/);
  assert.match(admin, /\$is_locked/);
});

test('Review exposes a safe page approve action only for eligible AI classifications', () => {
  assert.match(admin, /'review' === \$section[\s\S]*?value="approve_selected" data-visual-bulk/);
  assert.match(js, /'approve_selected'/);
  assert.match(admin, /Approve page/);
  assert.match(admin, /approve_visual_tones\( \$ids \)/);
  assert.match(repo, /function approve_visual_tones\( array \$snapshot_ids \)/);
  assert.match(repo, /human_locked = 0 AND manual_locked = 0 AND tone_status = 'classified'/);
  assert.match(repo, /tone IN \(\{\$tone_tokens\}\)/);
});

test('automation state is presented as a compact control-room summary', () => {
  assert.match(admin, /mac-tracker-visual-auto--v4/);
  assert.match(admin, /mac-tracker-automation-state/);
  assert.match(admin, /Advanced controls/);
  assert.match(css, /\.mac-tracker-automation-state\{/);
  assert.match(css, /\.mac-tracker-advanced-controls\{/);
});

test('card actions stay inside each card, with approval only on eligible review cards and a visible skip control', () => {
  const cardStart = admin.indexOf('<article class="mac-tracker-visual-card');
  const cardEnd = admin.indexOf('</article>', cardStart);
  const card = admin.slice(cardStart, cardEnd);
  assert.match(card, /mac-tracker-visual-card__quick-actions/);
  assert.match(card, /! \$is_locked && \$valid_tone/);
  assert.match(card, /mac-tracker-button--skip/);
  assert.match(card, /\$is_locked \).*?unlock_tone_/s);
  assert.doesNotMatch(card, /\$is_locked \? 'recapture_one_'/);
});

test('monitor and skipped-project surfaces use clear button treatments', () => {
  assert.match(admin, /class="button" data-workflow-view-all/);
  assert.match(admin, /mac-tracker-workflow-pagination__controls/);
  assert.match(css, /\.mac-tracker-workflow-pagination__controls\{/);
  assert.match(css, /\.mac-tracker-table-shell\{/);
  assert.match(css, /\.mac-tracker-button--skip\{/);
  // V4 deliberately adds authenticated presentation-only fragments and
  // bounded All-mode rows. Operational mutations remain in admin-actions.
  assert.match(ui, /mac_tracker_load_fragment/);
  assert.match(ui, /mac_tracker_load_project_rows/);
  assert.match(ui, /credentials: 'same-origin'/);
});
