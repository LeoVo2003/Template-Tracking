import sharp from 'sharp';
import { activateLazyContent, collectUiSamples, hideMediaForPreview } from './extract-ui.mjs';
import { validatePage, PageValidationError } from './validate-page.mjs';
import { homepageCandidates } from './homepage-resolver.mjs';
import { summarizeUiSamples } from './metrics.mjs';
import { analyzeUiColor } from './color-engine.mjs';

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

async function resolveHomepage(page, requestedUrl) {
  const candidates = homepageCandidates(requestedUrl);
  const rejected = [];
  for (const candidate of candidates) {
    try {
      const response = await page.goto(candidate, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const validation = await validatePage(page, response, candidate);
      return { response, validation, candidateUrls: candidates, resolvedCaptureUrl: candidate, resolutionStrategy: candidates.length > 1 ? ('/home/' === new URL(candidate).pathname ? 'root_prefer_home' : 'root_fallback') : 'stored_path' };
    } catch (error) {
      const code = error?.code || error?.name || 'NAV_ERROR';
      rejected.push({ url: candidate, code, message: String(error?.message || 'Candidate could not be loaded.').replace(/\s+/g, ' ').slice(0, 180) });
    }
  }
  const context = rejected.map((entry) => { try { return `${new URL(entry.url).pathname || '/'} → ${entry.code}`; } catch { return `${entry.url} → ${entry.code}`; } }).join('; ');
  throw new PageValidationError('HOMEPAGE_RESOLUTION_FAILED', `No valid homepage candidate. ${context}`, { requested_url: requestedUrl, candidate_urls: candidates, candidates: rejected });
}

export async function captureRenderedPage(browser, requestedUrl, snapshotId, runId) {
  const started = Date.now();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  try {
    const resolved = await resolveHomepage(page, requestedUrl);
    const { response, validation: firstValidation, candidateUrls, resolvedCaptureUrl, resolutionStrategy } = resolved;
    await activateLazyContent(page);
    await stabilize(page);
    const validation = await validatePage(page, response, resolvedCaptureUrl);
    const samples = await collectUiSamples(page);
    const pageHeight = await page.evaluate(() => Math.max(document.body?.scrollHeight || 0, document.documentElement?.scrollHeight || 0));
    const full = await page.screenshot({ fullPage: true, type: 'jpeg', quality: 58 });
    await hideMediaForPreview(page);
    await page.waitForTimeout(140);
    const preview = await sharp(await page.screenshot({ fullPage: true, type: 'jpeg', quality: 64 })).resize({ width: 768, withoutEnlargement: true }).jpeg({ quality: 66 }).toBuffer();
    const pixel = await analyzeUiColor(preview, samples);
    const metrics = { ...summarizeUiSamples(samples), semantic_model: pixel, metrics_version: 5, scope: 'brand_canvas_ui_only' };
    const capturedAt = new Date().toISOString();
    return {
      full,
      preview,
      bundle: {
        version: 4,
        snapshot_id: snapshotId,
        run_id: runId,
        requested_url: requestedUrl,
        candidate_urls: candidateUrls,
        resolved_capture_url: resolvedCaptureUrl,
        resolution_strategy: resolutionStrategy,
        final_url: validation.final_url,
        http_status: validation.http_status,
        redirect_count: validation.redirect_count,
        page_title: validation.page_title,
        viewport: { width: 1440, height: 900 },
        page_height: pageHeight,
        captured_at: capturedAt,
        render_ms: Date.now() - started,
        validation: { ...validation, initial: firstValidation },
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
