import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { dismissObstructiveOverlays } from '../.github/scripts/visual/capture.mjs';
import { collectUiSamples } from '../.github/scripts/visual/extract-ui.mjs';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });

try {
  await page.setContent(`<!doctype html><html style="overflow:hidden"><head><style>
    body{margin:0;overflow:hidden;font:16px sans-serif;background:#f7faf8;color:#24342b}
    .sticky-header{position:sticky;top:0;z-index:20;height:64px;background:#6b8f71;color:white}
    .hero{height:720px;background:#eaf2ec}.gallery{height:900px;background:#fff}
    .floating-cta{position:fixed;right:20px;bottom:20px;z-index:1500;width:130px;height:44px;background:#4e6b55;color:#fff}
    .modal-backdrop{position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.65)}
    .elementor-popup-modal{position:fixed;z-index:9001;left:20vw;top:18vh;width:60vw;height:55vh;background:#ff00ff;color:#fff}
    .dialog-close-button{position:absolute;right:12px;top:12px}
  </style></head><body>
    <header class="sticky-header">Real navigation</header><main><section class="hero">Real hero</section><section class="gallery">Real gallery</section></main>
    <button class="floating-cta">Book now</button><div class="modal-backdrop"></div>
    <div class="elementor-popup-modal" role="dialog" aria-modal="true"><button class="dialog-close-button" aria-label="Close">Close</button><p>Promotion popup</p></div>
    <script>document.querySelector('.dialog-close-button').addEventListener('click',()=>document.querySelector('.elementor-popup-modal').remove())</script>
  </body></html>`);

  const cleanup = await dismissObstructiveOverlays(page);
  const samples = await collectUiSamples(page);
  const screenshot = await page.screenshot({ fullPage: true, type: 'jpeg', quality: 50 });
  const state = await page.evaluate(() => ({
    popup: Boolean(document.querySelector('.elementor-popup-modal')),
    backdrop: Boolean(document.querySelector('.modal-backdrop')),
    sticky: Boolean(document.querySelector('.sticky-header')),
    cta: Boolean(document.querySelector('.floating-cta')),
    hero: Boolean(document.querySelector('.hero')),
    gallery: Boolean(document.querySelector('.gallery')),
    overflow: getComputedStyle(document.body).overflowY,
  }));

  assert.equal(state.popup, false);
  assert.equal(state.backdrop, false);
  assert.equal(state.sticky, true);
  assert.equal(state.cta, true);
  assert.equal(state.hero, true);
  assert.equal(state.gallery, true);
  assert.doesNotMatch(state.overflow, /hidden|clip/);
  assert.ok(screenshot.length > 1000);
  assert.equal(samples.some((sample) => /255\s*,\s*0\s*,\s*255/.test(String(sample.color))), false);
  assert.ok(cleanup.detected >= 2);
  assert.ok(cleanup.closed >= 1);
  assert.ok(cleanup.removed >= 1);
  assert.equal(cleanup.scroll_restored, true);
  process.stdout.write(`${JSON.stringify({ cleanup, state, sample_count: samples.length, screenshot_bytes: screenshot.length })}\n`);
} finally {
  await browser.close();
}
