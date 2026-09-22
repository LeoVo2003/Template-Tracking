import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [admin, css, ui, docs, agents] = await Promise.all([
  readFile('includes/class-mac-tracker-admin.php', 'utf8'),
  readFile('assets/botanical.css', 'utf8'),
  readFile('assets/botanical-ui.js', 'utf8'),
  readFile('docs/MAC-BOTANICAL-UI.md', 'utf8'),
  readFile('AGENTS.md', 'utf8'),
]);

test('repository design authority and Botanical tokens are committed', () => {
  assert.match(agents, /read docs\/MAC-BOTANICAL-UI\.md first/);
  for (const token of ['--mac-forest-900', '--mac-sage-600', '--mac-linen-100', '--mac-paper']) {
    assert.match(docs, new RegExp(token));
    assert.match(css, new RegExp(token));
  }
});

test('standalone shell is route-scoped and exposes exactly five product destinations', () => {
  assert.match(admin, /admin_body_class/);
  assert.match(css, /body\.mac-tracker-app-page #adminmenumain/);
  const navBody = admin.match(/private function app_navigation[\s\S]*?private function editorial_footer_band/)?.[0] || '';
  for (const label of ['Dashboard', 'Projects', 'AI Analysis', 'Skipped Projects', 'Settings']) assert.match(navBody, new RegExp(label));
  assert.equal((navBody.match(/=> array\(/g) || []).length, 5);
  assert.match(admin, /add_submenu_page\( null, 'Color Review'/);
  assert.match(admin, /add_submenu_page\( null, 'Pin import'/);
});

test('all five pages share one page-end editorial component', () => {
  assert.match(admin, /private function page_end\(\)[\s\S]*editorial_footer_band/);
  for (const quote of ['Good websites grow businesses', 'Good systems make good work visible', 'Turn websites into insights', 'Keep the signal. Remove the noise', 'Better tools create better work']) assert.match(admin, new RegExp(quote.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
});

test('AI Analysis and Settings are presentation composites over existing handlers', () => {
  for (const tab of ['action', 'processing', 'review', 'locked', 'workflow']) assert.match(admin, new RegExp(`data-ai-tab="${tab}"`));
  for (const tab of ['import', 'connections', 'automation', 'data']) assert.match(admin, new RegExp(`data-settings-tab="${tab}"`));
  assert.match(admin, /render_color_review_content/);
  assert.match(admin, /render_pin_import_content/);
  assert.doesNotMatch(ui, /fetch\(|XMLHttpRequest|admin-ajax\.php/);
});

test('responsive and accessibility contracts are present', () => {
  for (const width of ['1250px', '960px', '767px', '480px']) assert.match(css, new RegExp(width));
  assert.match(css, /prefers-reduced-motion/);
  assert.match(css, /:focus-visible/);
  assert.match(ui, /ArrowLeft/);
  assert.match(ui, /Escape/);
});
