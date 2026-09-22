import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');
const [bootstrap, app, admin, repository, css, capture, overlay, workflow] = await Promise.all([
  read('mac-project-tracker.php'),
  read('includes/class-mac-tracker-app.php'),
  read('includes/class-mac-tracker-admin.php'),
  read('includes/class-mac-tracker-repository.php'),
  read('assets/standalone.css'),
  read('.github/scripts/visual/capture.mjs'),
  read('.github/scripts/visual/overlay-policy.mjs'),
  read('.github/workflows/capture-visual-tone.yml'),
]);

test('clean standalone routes reuse WordPress auth and map every legacy page', () => {
  for (const route of ['mac-project-tracker/', 'mac-project-tracker/projects/', 'mac-project-tracker/analysis/', 'mac-project-tracker/skipped/', 'mac-project-tracker/settings/']) assert.match(app, new RegExp(route.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  assert.match(app, /is_user_logged_in\(\)/);
  assert.match(app, /wp_login_url\( \$this->current_url\(\) \)/);
  assert.match(app, /current_user_can\( 'manage_options' \)/);
  assert.match(app, /'response' => 403/);
  for (const page of ['mac-project-tracker-dashboard', 'mac-project-tracker-visuals', 'mac-project-tracker-colors', 'mac-project-tracker-pins']) assert.match(app, new RegExp(page));
  assert.match(bootstrap, /new MAC_Tracker_App\( \$admin \)/);
});

test('standalone assets are isolated from legacy admin CSS and preserve localized operational scripts', () => {
  const method = admin.slice(admin.indexOf('public function enqueue_standalone_assets'), admin.indexOf('public function render_dashboard'));
  assert.match(method, /assets\/standalone\.css/);
  assert.match(method, /macTrackerVisual/);
  assert.match(method, /macTrackerWorkflow/);
  assert.match(method, /macTrackerColors/);
  assert.match(method, /if \( \$standalone \)/);
  assert.doesNotMatch(method.slice(method.indexOf('if ( $standalone )'), method.indexOf('} else {')), /admin\.css|botanical\.css/);
});

test('V2 presentation has an editorial asset system and responsive application shell', () => {
  for (const token of ['--mac-forest-950', '--mac-linen', '--mac-paper', '--mac-sidebar']) assert.match(css, new RegExp(token));
  assert.match(css, /editorial\/olive-linen\.webp/);
  assert.match(css, /editorial\/forest-blossom\.webp/);
  for (const width of ['1250px', '960px', '767px', '480px']) assert.match(css, new RegExp(width));
  assert.match(css, /Cormorant Garamond/);
  assert.match(css, /JetBrains Mono/);
  assert.match(css, /mac-tracker-color-review.*repeat\(4/s);
  assert.match(css, /prefers-reduced-motion/);
});

test('AUTO observability is read-only and the production schedule remains serialized at eleven sites', () => {
  assert.match(repository, /function visual_automation_observability/);
  assert.match(repository, /source_action = 'scheduled_auto'/);
  assert.match(repository, /INTERVAL 60 MINUTE/);
  assert.match(admin, /Last scheduled run/);
  assert.match(admin, /Last successful batch/);
  assert.match(admin, /Every 15 min · :02, :17, :32, :47/);
  assert.match(workflow, /cron:\s*"2,17,32,47 \* \* \* \*"/);
  assert.match(workflow, /default:\s*"11"/);
  assert.match(workflow, /cancel-in-progress:\s*false/);
});

test('capture validates security first and performs bounded popup cleanup before final capture', () => {
  const worker = capture.slice(capture.indexOf('export async function captureRenderedPage'));
  assert.ok(worker.indexOf('resolveHomepage(page, requestedUrl)') < worker.indexOf('activateLazyContent(page)'));
  assert.ok(worker.indexOf('stabilize(page)') < worker.indexOf('dismissObstructiveOverlays(page)'));
  assert.ok(worker.indexOf('dismissObstructiveOverlays(page)') < worker.indexOf("window.scrollTo(0, 0)"));
  assert.ok(worker.indexOf("window.scrollTo(0, 0)") < worker.lastIndexOf('validatePage(page'));
  assert.ok(worker.lastIndexOf('validatePage(page') < worker.indexOf('collectUiSamples(page)'));
  assert.ok(worker.indexOf('collectUiSamples(page)') < worker.indexOf('page.screenshot({ fullPage: true'));
  assert.match(capture, /pass < 2/);
  assert.match(capture, /remaining: remaining\.length/);
  assert.match(capture, /cookie-consent/);
  assert.match(capture, /third_party_chat/);
  assert.match(overlay, /verified_cookie_banner/);
  assert.match(overlay, /obstructive_third_party_chat/);
});

test('existing mutation endpoints and core Visual Tone contracts remain wired', () => {
  for (const action of ['mac_tracker_save_settings', 'mac_tracker_queue_sync', 'mac_tracker_requeue_visuals', 'mac_tracker_approve_visual_tone', 'mac_tracker_skip_project', 'mac_tracker_restore_exclusion']) assert.match(admin, new RegExp(action));
  for (const hook of ['mac_tracker_visual_action', 'mac_tracker_visual_status', 'mac_tracker_visual_runs']) assert.match(admin, new RegExp(hook));
  assert.match(admin, /wp_nonce_field\( 'mac_tracker_requeue_visuals' \)/);
  assert.match(admin, /data-ai-tab="review"/);
  assert.match(admin, /data-ai-tab="locked"/);
});
