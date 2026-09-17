import { chromium } from 'playwright';
import { mkdir, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { captureRenderedPage } from './capture.mjs';
import { classifyTone } from './classify.mjs';
import { PageValidationError } from './validate-page.mjs';

const siteUrl = String(process.env.MAC_TRACKER_SITE_URL || '').replace(/\/+$/, '');
const secret = String(process.env.MAC_TRACKER_AUTOMATION_SECRET || '');
const limit = Math.max(1, Math.min(25, Number(process.env.BATCH_LIMIT || 10)));
const cloudflareAccount = String(process.env.CLOUDFLARE_ACCOUNT_ID || '');
const cloudflareToken = String(process.env.CLOUDFLARE_API_TOKEN || '');
const groqApiKey = String(process.env.GROQ_API_KEY || '');
const geminiApiKey = String(process.env.GEMINI_API_KEY || '');
const freeOnly = 'false' !== String(process.env.FREE_ONLY || 'true').trim().toLowerCase();
const apiBase = `${siteUrl}/wp-json/mac-tracker/v1/visual`;
const workDir = join(process.cwd(), '.visual-capture');

if (!siteUrl || !secret) throw new Error('MAC_TRACKER_SITE_URL and MAC_TRACKER_AUTOMATION_SECRET are required.');
if (!freeOnly) throw new Error('FREE_ONLY must be true. This workflow is prohibited from selecting a paid provider.');

const headers = {
  'X-MAC-Tracker-Automation': secret,
  'User-Agent': 'MAC-Project-Tracker-GitHub-Action/0.16.0',
  Accept: 'application/json',
};

await mkdir(workDir, { recursive: true });

async function queue(stage) {
  const response = await fetch(`${apiBase}/queue`, {
    method: 'POST',
    headers: { ...headers, 'Content-Type': 'application/json' },
    body: JSON.stringify({ stage, limit }),
  });
  if (!response.ok) throw new Error(`Queue ${stage} failed: HTTP ${response.status} ${(await response.text()).replace(/\s+/g, ' ').slice(0, 700)}`);
  const payload = await response.json();
  return payload.items || [];
}

async function ingest(form) {
  const response = await fetch(`${apiBase}/ingest`, { method: 'POST', headers, body: form });
  if (!response.ok) throw new Error(`Ingest failed: HTTP ${response.status} ${(await response.text()).replace(/\s+/g, ' ').slice(0, 700)}`);
  return response.json();
}

async function postJson(payload) {
  const form = new FormData();
  for (const [key, value] of Object.entries(payload)) form.append(key, String(value));
  return ingest(form);
}

async function uploadCapture(item, captured) {
  const form = new FormData();
  form.append('mode', 'capture');
  form.append('snapshot_id', String(item.id));
  form.append('job_token', item.job_token);
  form.append('bundle_json', JSON.stringify(captured.bundle));
  form.append('screenshot', new Blob([captured.full], { type: 'image/jpeg' }), `mac-tracker-${item.id}.jpg`);
  form.append('ai_preview', new Blob([captured.preview], { type: 'image/jpeg' }), `mac-tracker-${item.id}-ai-preview.jpg`);
  return ingest(form);
}

async function reportFailure(mode, item, error) {
  try {
    await postJson({ mode, snapshot_id: item.id, job_token: item.job_token, error_code: error.code || (error instanceof PageValidationError ? 'PAGE_VALIDATION_FAILED' : 'WORKER_ERROR'), message: error.message });
  } catch (reportError) {
    console.warn(`Failure callback ignored for #${item.id}: ${reportError.message}`);
  }
}

async function captureBatch() {
  const items = await queue('capture');
  if (!items.length) return 0;
  const browser = await chromium.launch({ headless: true });
  try {
    for (const item of items) {
      try {
        const captured = await captureRenderedPage(browser, item.website_url, item.id, item.job_token);
        await uploadCapture(item, captured);
        console.log(`Captured bundle #${item.id}: ${captured.bundle.http_status} ${captured.bundle.final_url}`);
      } catch (error) {
        await reportFailure('capture_failed', item, error);
        console.warn(`Capture failed #${item.id}: ${error.message}`);
      }
    }
  } finally {
    await browser.close();
  }
  return items.length;
}

async function downloadPreview(item) {
  let bundle = {};
  try { bundle = JSON.parse(item.capture_bundle_json || '{}'); } catch { bundle = {}; }
  const previewUrl = bundle?.artifacts?.ai_preview_url || item.screenshot_url;
  if (!previewUrl) throw new Error('Capture bundle has no AI preview or screenshot URL.');
  const response = await fetch(previewUrl);
  if (!response.ok) throw new Error(`AI preview download failed: HTTP ${response.status}`);
  return { bundle, preview: Buffer.from(await response.arrayBuffer()) };
}

async function classifyPendingBatch() {
  const items = await queue('tone');
  for (const item of items) {
    try {
      const { bundle, preview } = await downloadPreview(item);
      const evidence = bundle?.ui?.metrics || { text: 'Capture bundle has no deterministic UI metrics.' };
      const outcome = await classifyTone({ previewBuffer: preview, evidence, groqApiKey, geminiApiKey, cloudflareAccount, cloudflareToken, freeOnly });
      const rawJson = JSON.stringify({ phase: 4, ...outcome, deterministic: evidence });
      if ('classified' === outcome.state) {
        const result = outcome.result;
        await postJson({ mode: 'tone', snapshot_id: item.id, job_token: item.job_token, tone: result.tone, confidence: result.confidence, reason: result.reason, provider: result.provider, model: result.model, needs_review: result.needs_review, raw_json: rawJson });
        console.log(`Classified #${item.id} with ${result.provider}/${result.model}: ${result.tone} @ ${result.confidence}`);
      } else if ('retry_wait' === outcome.state) {
        const quota = 'FREE_QUOTA_EXHAUSTED' === outcome.retry_code;
        await postJson({ mode: 'tone_retry', snapshot_id: item.id, job_token: item.job_token, error_code: outcome.retry_code, message: quota ? 'All configured free visual-tone providers are quota limited. No paid provider was used.' : 'All configured free visual-tone providers are temporarily unavailable. No paid provider was used.', raw_json: rawJson, retry_after_seconds: quota ? 1800 : 900 });
        console.warn(`Tone #${item.id} deferred: ${quota ? 'all free provider quotas exhausted' : 'all free providers temporarily unavailable'}.`);
      } else {
        const result = outcome.result;
        await postJson({ mode: 'tone_needs_review', snapshot_id: item.id, job_token: item.job_token, tone: result.tone, confidence: result.confidence, reason: result.reason, provider: result.provider, model: result.model, raw_json: rawJson });
        console.warn(`Tone #${item.id} requires review; no free provider result passed the confidence gate.`);
      }
    } catch (error) {
      await reportFailure('tone_failed', item, error);
      console.warn(`Tone failed #${item.id}: ${error.message}`);
    }
  }
  return items.length;
}

try {
  await captureBatch();
  await classifyPendingBatch();
} finally {
  await rm(workDir, { recursive: true, force: true });
}
