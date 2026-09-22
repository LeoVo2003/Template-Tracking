import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium } from 'playwright';
import { dismissObstructiveOverlays } from '../.github/scripts/visual/capture.mjs';
import { collectUiSamples } from '../.github/scripts/visual/extract-ui.mjs';
import { validatePage } from '../.github/scripts/visual/validate-page.mjs';

const qaDir = process.env.MAC_QA_DIR || join(process.cwd(), 'tmp', 'popup-cleanup-qa');
await mkdir(qaDir, { recursive: true });
const browser = await chromium.launch({ headless: true });

const base = (overlay) => `<!doctype html><html><head><style>
html,body{margin:0;font:16px Arial,sans-serif;background:#f6f3ec;color:#24342b}body{min-height:1800px}
.sticky-header{position:sticky;top:0;z-index:50;height:64px;background:#344b3b;color:white;padding:20px}
.hero{height:680px;padding:48px;background:#e8eee4}.gallery{height:960px;padding:48px;background:#fff}
.booking-cta{position:fixed;right:20px;bottom:20px;z-index:1500;width:145px;height:46px;background:#344b3b;color:#fff}
.modal-backdrop,.pum-overlay{position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.65)}
.elementor-popup-modal,.modal,.aria-popup{position:fixed;z-index:9001;left:20vw;top:18vh;width:60vw;height:55vh;background:#d892a6;color:#fff;padding:28px}
.cookie-consent{position:fixed;z-index:8000;left:0;right:0;bottom:0;min-height:150px;background:#f2dfbe;padding:30px}
.chat-widget-obstructive{position:fixed;z-index:7000;right:0;bottom:0;width:43vw;height:44vh;background:#d8e7ef;padding:24px}
</style></head><body><header class="sticky-header">Real navigation</header><main><section class="hero">Real hero</section><section class="gallery">Real gallery</section></main><button class="booking-cta">Book appointment</button>${overlay}</body></html>`;

const fixtures = [
  ['elementor', '<div class="modal-backdrop"></div><div class="elementor-popup-modal" role="dialog" aria-modal="true"><button class="dialog-close-button" aria-label="Close">Close</button><p>Promotion popup</p></div><script>document.querySelector(".dialog-close-button").onclick=()=>document.querySelector(".elementor-popup-modal").remove()</script>'],
  ['aria-modal', '<div class="aria-popup" role="dialog" aria-modal="true"><button aria-label="Close">Close</button><p>Newsletter modal</p></div>'],
  ['cookie-banner', '<section class="cookie-consent"><p>We use cookies.</p><button>Accept all</button></section><script>document.querySelector(".cookie-consent button").onclick=()=>document.querySelector(".cookie-consent").remove()</script>'],
  ['backdrop', '<div class="modal-backdrop"></div><div class="modal"><button data-bs-dismiss="modal">Dismiss</button><p>Bootstrap modal</p></div>'],
  ['chat-widget', '<aside class="chat-widget-obstructive"><button aria-label="Close">Close</button><p>Third-party chat support</p></aside>'],
];

const results = [];
try {
  for (const [name, overlay] of fixtures) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    await page.setContent(base(overlay));
    await page.evaluate(() => { document.documentElement.style.overflow = 'hidden'; document.body.style.overflow = 'hidden'; });
    await page.screenshot({ path: join(qaDir, `${name}-before.png`), fullPage: false });
    const cleanup = await dismissObstructiveOverlays(page);
    const samples = await collectUiSamples(page);
    await page.screenshot({ path: join(qaDir, `${name}-after.png`), fullPage: false });
    const state = await page.evaluate(() => ({
      sticky: Boolean(document.querySelector('.sticky-header')),
      cta: Boolean(document.querySelector('.booking-cta')),
      hero: Boolean(document.querySelector('.hero')),
      remaining: Boolean(document.querySelector('.elementor-popup-modal,.aria-popup,.cookie-consent,.modal-backdrop,.modal,.chat-widget-obstructive')),
      overflow: `${getComputedStyle(document.documentElement).overflowY} ${getComputedStyle(document.body).overflowY}`,
    }));
    assert.equal(state.sticky, true, `${name}: sticky navigation must remain`);
    assert.equal(state.cta, true, `${name}: booking CTA must remain`);
    assert.equal(state.hero, true, `${name}: hero must remain`);
    assert.equal(state.remaining, false, `${name}: verified obstruction must be gone`);
    assert.doesNotMatch(state.overflow, /hidden|clip/, `${name}: scroll lock must be restored`);
    assert.ok(cleanup.detected >= 1, `${name}: obstruction must be detected`);
    assert.equal(cleanup.remaining, 0, `${name}: metadata must report no residue`);
    assert.ok(cleanup.passes >= 1 && cleanup.passes <= 2, `${name}: cleanup must be bounded`);
    assert.ok(samples.length > 0, `${name}: UI collection still runs`);
    results.push({ name, cleanup });
    await page.close();
  }

  const securityPage = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  await securityPage.setContent('<!doctype html><title>Just a moment...</title><body><h1>Performing security verification</h1><p>Verify you are human</p></body>');
  await assert.rejects(() => validatePage(securityPage, { status: () => 200, request: () => null }, 'https://blocked.example.test'), (error) => error?.code === 'CF_CHALLENGE');
  await securityPage.close();
  process.stdout.write(`${JSON.stringify({ qa_dir: qaDir, fixtures: results, security_precheck: 'CF_CHALLENGE' })}\n`);
} finally {
  await browser.close();
}
