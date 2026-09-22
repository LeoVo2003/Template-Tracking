import sharp from 'sharp';
import { activateLazyContent, collectUiSamples, hideMediaForPreview } from './extract-ui.mjs';
import { validatePage, PageValidationError, isSecurityBlockError } from './validate-page.mjs';
import { homepageCandidates } from './homepage-resolver.mjs';
import { summarizeUiSamples } from './metrics.mjs';
import { analyzeUiColor } from './color-engine.mjs';
import { classifyOverlayEvidence } from './overlay-policy.mjs';

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

const overlayCloseSelectors = [
  '[aria-label="Close"]',
  '[aria-label="close"]',
  '[data-dismiss="modal"]',
  '[data-bs-dismiss="modal"]',
  '.dialog-close-button',
  '.pum-close',
  '.mfp-close',
  '.modal .close',
  '.dialog-close',
];

async function scanOverlayEvidence(page) {
  return page.evaluate((closeSelectors) => {
    const selectors = [
      '[role="dialog"]', '[aria-modal="true"]', '.elementor-popup-modal',
      '.pum', '.pum-container', '.pum-overlay', '.modal', '.modal-backdrop',
      '.mfp-wrap', '.mfp-bg', '.dialog-overlay', '[class*="popup"]',
      '[class*="modal"]', '[class*="overlay"]', '[style*="position: fixed"]',
      '[style*="position:fixed"]',
    ];
    const elements = [...new Set(document.querySelectorAll(selectors.join(',')))];
    const viewportArea = Math.max(1, innerWidth * innerHeight);
    const rows = [];
    let sequence = 0;
    for (const element of elements.slice(0, 400)) {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      const visible = style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) > 0.03 && rect.width > 10 && rect.height > 10;
      if (!visible) continue;
      const hint = `${element.className || ''} ${element.id || ''}`.toLowerCase();
      let type = 'generic_overlay';
      if (/elementor-popup-modal/.test(hint)) type = 'elementor_popup';
      else if (/pum-overlay/.test(hint)) type = 'pum_backdrop';
      else if (/\bpum\b|pum-container/.test(hint)) type = 'pum_popup';
      else if (/modal-backdrop/.test(hint)) type = 'modal_backdrop';
      else if (/mfp-bg/.test(hint)) type = 'mfp_backdrop';
      else if (/mfp-wrap/.test(hint)) type = 'mfp_popup';
      else if (element.getAttribute('aria-modal') === 'true') type = 'aria_modal';
      else if (/(^|\s)modal(\s|$)/.test(hint)) type = 'bootstrap_modal';
      const token = element.getAttribute('data-mac-overlay-token') || `mac-overlay-${Date.now()}-${sequence++}`;
      element.setAttribute('data-mac-overlay-token', token);
      const clippedWidth = Math.max(0, Math.min(innerWidth, rect.right) - Math.max(0, rect.left));
      const clippedHeight = Math.max(0, Math.min(innerHeight, rect.bottom) - Math.max(0, rect.top));
      rows.push({
        token,
        type,
        visible,
        fixed: style.position === 'fixed',
        sticky: style.position === 'sticky',
        z_index: Number.parseInt(style.zIndex, 10) || 0,
        coverage: Number(((clippedWidth * clippedHeight) / viewportArea).toFixed(4)),
        has_close_control: closeSelectors.some((selector) => Boolean(element.matches(selector) || element.querySelector(selector))),
        aria_modal: element.getAttribute('aria-modal') === 'true',
        role_dialog: element.getAttribute('role') === 'dialog',
      });
    }
    return rows;
  }, overlayCloseSelectors);
}

/** Close or remove only verified obstructive overlays after lazy scrolling. */
export async function dismissObstructiveOverlays(page) {
  const initial = await bounded(scanOverlayEvidence(page), 3500, []);
  const detected = (Array.isArray(initial) ? initial : []).filter((row) => classifyOverlayEvidence(row).obstructive);
  const detectedTokens = new Set(detected.map((row) => row.token));
  const types = new Set(detected.map((row) => classifyOverlayEvidence(row).type));

  if (detected.length) {
    await bounded(page.evaluate(({ tokens, closeSelectors }) => {
      for (const token of tokens) {
        const element = document.querySelector(`[data-mac-overlay-token="${token}"]`);
        if (!element) continue;
        const close = closeSelectors.map((selector) => element.matches(selector) ? element : element.querySelector(selector)).find(Boolean);
        if (close && 'function' === typeof close.click) close.click();
      }
    }, { tokens: [...detectedTokens], closeSelectors: overlayCloseSelectors }), 2500, null);
    await page.keyboard.press('Escape').catch(() => null);
    await page.waitForTimeout(320);
  }

  const afterClose = await bounded(scanOverlayEvidence(page), 3500, []);
  const remaining = (Array.isArray(afterClose) ? afterClose : []).filter((row) => classifyOverlayEvidence(row).obstructive);
  const remainingTokens = new Set(remaining.map((row) => row.token));
  const closed = detected.filter((row) => !remainingTokens.has(row.token)).length;
  remaining.forEach((row) => types.add(classifyOverlayEvidence(row).type));
  const removed = await bounded(page.evaluate((tokens) => {
    let count = 0;
    for (const token of tokens) {
      const element = document.querySelector(`[data-mac-overlay-token="${token}"]`);
      if (!element || !element.isConnected) continue;
      element.remove();
      count += 1;
    }
    return count;
  }, remaining.map((row) => row.token)), 2500, 0);

  const scrollRestored = await bounded(page.evaluate(() => {
    const html = document.documentElement;
    const body = document.body;
    const canScroll = Math.max(html?.scrollHeight || 0, body?.scrollHeight || 0) > innerHeight + 40;
    const locked = canScroll && [html, body].some((element) => element && /hidden|clip/.test(getComputedStyle(element).overflowY || getComputedStyle(element).overflow));
    if (locked) {
      [html, body].forEach((element) => {
        if (!element) return;
        element.style.removeProperty('overflow');
        element.style.removeProperty('overflow-y');
        if (/hidden|clip/.test(getComputedStyle(element).overflowY || getComputedStyle(element).overflow)) element.style.setProperty('overflow-y', 'auto', 'important');
      });
    }
    document.querySelectorAll('[data-mac-overlay-token]').forEach((element) => element.removeAttribute('data-mac-overlay-token'));
    return !canScroll || ![html, body].some((element) => element && /hidden|clip/.test(getComputedStyle(element).overflowY || getComputedStyle(element).overflow));
  }), 2500, false);

  return {
    detected: Math.max(detected.length, closed + Number(removed || 0)),
    closed,
    removed: Number(removed || 0),
    scroll_restored: Boolean(scrollRestored),
    types: [...types].filter(Boolean).slice(0, 12),
  };
}

async function resolveHomepage(page, requestedUrl) {
  const candidates = homepageCandidates(requestedUrl);
  const rejected = [];
  let diagnosticScreenshot = null;
  let diagnosticDetails = {};
  for (const candidate of candidates) {
    try {
      const response = await page.goto(candidate, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const validation = await validatePage(page, response, candidate);
      return { response, validation, candidateUrls: candidates, resolvedCaptureUrl: candidate, resolutionStrategy: candidates.length > 1 ? ('/home/' === new URL(candidate).pathname ? 'root_prefer_home' : 'root_fallback') : 'stored_path' };
    } catch (error) {
      if (isSecurityBlockError(error)) {
        try {
          diagnosticScreenshot = await page.screenshot({ type: 'jpeg', quality: 68, fullPage: false });
          diagnosticDetails = { requested_url: candidate, resolved_capture_url: candidate, final_url: page.url(), runner_type: process.env.RUNNER_TYPE || 'github-hosted', diagnostic_captured_at: new Date().toISOString() };
        } catch { /* A blocked page can still refuse screenshots; preserve the error. */ }
      }
      const code = error?.code || error?.name || 'NAV_ERROR';
      rejected.push({ url: candidate, code, message: String(error?.message || 'Candidate could not be loaded.').replace(/\s+/g, ' ').slice(0, 180) });
    }
  }
  const context = rejected.map((entry) => { try { return `${new URL(entry.url).pathname || '/'} → ${entry.code}`; } catch { return `${entry.url} → ${entry.code}`; } }).join('; ');
  const last = rejected[rejected.length - 1] || {};
  throw new PageValidationError(last.code || 'HOMEPAGE_RESOLUTION_FAILED', last.message || `No valid homepage candidate. ${context}`, { requested_url: requestedUrl, candidate_urls: candidates, candidates: rejected, ...diagnosticDetails, diagnostic_screenshot: diagnosticScreenshot });
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
    const overlayCleanup = await dismissObstructiveOverlays(page);
    await page.waitForTimeout(360);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(240);
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
        version: 5,
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
        overlay_cleanup: overlayCleanup,
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
