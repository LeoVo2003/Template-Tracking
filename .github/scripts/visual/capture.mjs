import sharp from 'sharp';
import { activateLazyContent, collectUiSamples, hideMediaForPreview } from './extract-ui.mjs';
import { validatePage, PageValidationError } from './validate-page.mjs';
import { summarizeUiSamples } from './metrics.mjs';

const bounded = (promise, timeoutMs, fallback) => Promise.race([promise, new Promise((resolve) => setTimeout(() => resolve(fallback), timeoutMs))]);

async function waitForImages(page) {
  await bounded(page.evaluate(() => Promise.all([...document.images].map((image) => {
    if (image.complete) return Promise.resolve();
    return new Promise((resolve) => {
      image.addEventListener('load', resolve, { once: true });
      image.addEventListener('error', resolve, { once: true });
    });
  }))), 12000, null);
  await bounded(page.evaluate(() => Promise.all([...document.images].map((image) => image.decode ? image.decode().catch(() => null) : Promise.resolve()))), 7000, null);
}

async function stabilize(page) {
  await bounded(page.evaluate(() => document.fonts?.ready), 5000, null);
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => null);
  await page.waitForTimeout(450);
  let previousHeight = 0;
  let stablePasses = 0;
  for (let pass = 0; pass < 5 && stablePasses < 2; pass += 1) {
    const height = await page.evaluate(() => Math.max(document.body?.scrollHeight || 0, document.documentElement?.scrollHeight || 0));
    for (let top = 0; top < height; top += 720) {
      await page.evaluate((value) => window.scrollTo(0, value), top);
      await page.waitForTimeout(260);
    }
    await waitForImages(page);
    const nextHeight = await page.evaluate(() => Math.max(document.body?.scrollHeight || 0, document.documentElement?.scrollHeight || 0));
    if (nextHeight === previousHeight && nextHeight === height) stablePasses += 1; else stablePasses = 0;
    previousHeight = nextHeight;
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(700);
  await waitForImages(page);
  await page.addStyleTag({ content: `*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition:none!important;caret-color:transparent!important}` });
  await page.waitForTimeout(220);
}

export async function captureRenderedPage(browser, requestedUrl, snapshotId, runId) {
  const started = Date.now();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  try {
    const response = await page.goto(requestedUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await validatePage(page, response, requestedUrl);
    await activateLazyContent(page);
    await stabilize(page);
    const validation = await validatePage(page, response, requestedUrl);
    const samples = await collectUiSamples(page);
    const metrics = summarizeUiSamples(samples);
    const pageHeight = await page.evaluate(() => Math.max(document.body?.scrollHeight || 0, document.documentElement?.scrollHeight || 0));
    const full = await page.screenshot({ fullPage: true, type: 'jpeg', quality: 58 });
    await hideMediaForPreview(page);
    await page.waitForTimeout(140);
    const preview = await sharp(await page.screenshot({ fullPage: true, type: 'jpeg', quality: 64 })).resize({ width: 768, withoutEnlargement: true }).jpeg({ quality: 66 }).toBuffer();
    const capturedAt = new Date().toISOString();
    return {
      full,
      preview,
      bundle: {
        version: 2,
        snapshot_id: snapshotId,
        run_id: runId,
        requested_url: requestedUrl,
        final_url: validation.final_url,
        http_status: validation.http_status,
        redirect_count: validation.redirect_count,
        page_title: validation.page_title,
        viewport: { width: 1440, height: 900 },
        page_height: pageHeight,
        captured_at: capturedAt,
        render_ms: Date.now() - started,
        validation,
        ui: { samples, metrics },
        artifacts: { full_screenshot_url: null, ai_preview_url: null },
      },
    };
  } catch (error) {
    if (error instanceof PageValidationError) throw error;
    if (error?.name === 'TimeoutError') throw new PageValidationError('NAV_TIMEOUT', 'Homepage navigation timed out.', { requested_url: requestedUrl });
    throw error;
  } finally {
    await context.close();
  }
}
