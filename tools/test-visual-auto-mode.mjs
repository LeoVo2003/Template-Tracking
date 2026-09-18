import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const admin = fs.readFileSync('includes/class-mac-tracker-admin.php', 'utf8');
const service = fs.readFileSync('includes/class-mac-tracker-visual-service.php', 'utf8');
const worker = fs.readFileSync('.github/scripts/visual/main.mjs', 'utf8');
const js = fs.readFileSync('assets/admin.js', 'utf8');

test('admin save accepts only manual or auto and persists the selected mode', () => {
  assert.match(admin, /\$mode = isset\( \$_POST\['visual_mode'\] \).*\? 'auto' : 'manual';/s);
  assert.match(admin, /update_option\( 'mac_tracker_visual_mode', \$mode, false \)/);
  assert.match(admin, /\$mode = 'auto' === sanitize_key\( wp_unslash\( \$_POST\['visual_mode'\] \?\? '' \) \) \? 'auto' : 'manual';/);
});

test('REST visual config reflects the persisted mode and schedule honors auto', () => {
  assert.match(service, /\$mode = 'auto' === get_option\( 'mac_tracker_visual_mode', 'manual' \) \? 'auto' : 'manual';/);
  assert.match(service, /'mode' => \$mode/);
  assert.match(worker, /if \('schedule' === workflowEvent && 'auto' !== config\.mode\)/);
});

test('Auto is the only schedule switch and the status reflects a successful auto-save', () => {
  assert.doesNotMatch(admin, /data-enable-visual-schedule|>Turn on</);
  assert.match(admin, /data-visual-schedule-status/);
  assert.match(js, /function reflectMode\(mode\)/);
  assert.match(js, /reflectMode\(mode\);status\.textContent='Saved'/);
});
