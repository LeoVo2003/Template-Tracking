import { chromium } from 'playwright';
import { appendFile, mkdir, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { captureRenderedPage } from './capture.mjs';
import { classifyTone } from './classify.mjs';
import { normalizeJobScope } from './job-scope.mjs';
import { PageValidationError } from './validate-page.mjs';

const siteUrl = String(process.env.MAC_TRACKER_SITE_URL || '').replace(/\/+$/, '');
const secret = String(process.env.MAC_TRACKER_AUTOMATION_SECRET || '');
const scope = normalizeJobScope({ run_mode: process.env.RUN_MODE, stage: process.env.VISUAL_STAGE, target_ids: process.env.TARGET_IDS, limit: process.env.BATCH_LIMIT });
const cloudflareAccount = String(process.env.CLOUDFLARE_ACCOUNT_ID || '');
const cloudflareToken = String(process.env.CLOUDFLARE_API_TOKEN || '');
const groqApiKey = String(process.env.GROQ_API_KEY || '');
const geminiApiKey = String(process.env.GEMINI_API_KEY || '');
const freeOnly = 'false' !== String(process.env.FREE_ONLY || 'true').trim().toLowerCase();
const workflowEvent = String(process.env.WORKFLOW_EVENT || 'workflow_dispatch');
const apiBase = `${siteUrl}/wp-json/mac-tracker/v1/visual`;
const workDir = join(process.cwd(), '.visual-capture');
const summary = { claimed: 0, skipped: 0, captureSuccess: 0, captureBlocked: 0, captureFailed: 0, qwenAccepted: 0, geminiJudged: 0, needsReview: 0, providerDeferred: 0 };

if (!siteUrl || !secret) throw new Error('MAC_TRACKER_SITE_URL and MAC_TRACKER_AUTOMATION_SECRET are required.');
if (!freeOnly) throw new Error('FREE_ONLY must be true. This workflow is prohibited from selecting a paid provider.');

const headers = { 'X-MAC-Tracker-Automation': secret, 'User-Agent': 'MAC-Project-Tracker-GitHub-Action/3.0.0', Accept: 'application/json' };
await mkdir(workDir, { recursive: true });

async function claimJobs(requestedScope) {
  const response = await fetch(`${apiBase}/jobs/claim`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(requestedScope) });
  if (!response.ok) throw new Error(`Scoped job claim failed: HTTP ${response.status} ${(await response.text()).replace(/\s+/g, ' ').slice(0, 700)}`);
  const payload = await response.json();
  summary.claimed += (payload.claimed_ids || []).length;
  summary.skipped += (payload.skipped_ids || []).length;
  return payload.items || [];
}

async function workerConfig() {
  const response = await fetch(`${apiBase}/config`, { headers });
  if (!response.ok) throw new Error(`Visual config failed: HTTP ${response.status} ${(await response.text()).replace(/\s+/g, ' ').slice(0, 500)}`);
  return response.json();
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
  try { await postJson({ mode, snapshot_id: item.id, job_token: item.job_token, error_code: error.code || (error instanceof PageValidationError ? 'PAGE_VALIDATION_FAILED' : 'WORKER_ERROR'), message: error.message }); }
  catch (reportError) { console.warn(`Failure callback ignored for #${item.id}: ${reportError.message}`); }
}

async function processCaptureItems(items) {
  const capturedIds = [];
  if (!items.length) return capturedIds;
  const browser = await chromium.launch({ headless: true });
  try {
    for (const item of items) {
      try {
        const captured = await captureRenderedPage(browser, item.website_url, item.id, item.job_token);
        await uploadCapture(item, captured);
        capturedIds.push(item.id);
        summary.captureSuccess += 1;
        console.log(`Captured bundle #${item.id}: ${captured.bundle.http_status} ${captured.bundle.final_url}`);
      } catch (error) {
        await reportFailure('capture_failed', item, error);
        if (['CF_CHALLENGE', 'CAPTCHA', 'PARKED_DOMAIN', 'MAINTENANCE', 'LOGIN_WALL', 'BAD_REDIRECT', 'EMPTY_PAGE'].includes(error.code)) summary.captureBlocked += 1;
        else summary.captureFailed += 1;
        console.warn(`Capture failed #${item.id}: ${error.message}`);
      }
    }
  } finally { await browser.close(); }
  return capturedIds;
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

async function processToneItems(items, aiStrategy, autoAccept) {
  if ('off' === aiStrategy) { console.log('AI strategy is OFF; no saved screenshots were submitted for analysis.'); return; }
  for (const item of items) {
    try {
      const { bundle, preview } = await downloadPreview(item);
      const evidence = bundle?.ui?.metrics || { text: 'Capture bundle has no deterministic UI metrics.' };
      const outcome = await classifyTone({ previewBuffer: preview, evidence, groqApiKey, geminiApiKey, cloudflareAccount, cloudflareToken, freeOnly, strategy: aiStrategy, autoAccept });
      const rawJson = JSON.stringify({ phase: 4, ...outcome, deterministic: evidence });
      if ('classified' === outcome.state) {
        const result = outcome.result;
        await postJson({ mode: 'tone', snapshot_id: item.id, job_token: item.job_token, tone: result.tone, confidence: result.confidence, reason: result.reason, provider: result.provider, model: result.model, needs_review: result.needs_review, raw_json: rawJson });
        if ('gemini' === result.provider) summary.geminiJudged += 1; else summary.qwenAccepted += 1;
        console.log(`Classified #${item.id} with ${result.provider}/${result.model}: ${result.tone} @ ${result.confidence}`);
      } else if ('retry_wait' === outcome.state) {
        const quota = 'FREE_QUOTA_EXHAUSTED' === outcome.retry_code;
        await postJson({ mode: 'tone_retry', snapshot_id: item.id, job_token: item.job_token, error_code: outcome.retry_code, message: quota ? 'All configured free visual-tone providers are quota limited. No paid provider was used.' : 'All configured free visual-tone providers are temporarily unavailable. No paid provider was used.', raw_json: rawJson, retry_after_seconds: quota ? 1800 : 900 });
        summary.providerDeferred += 1;
      } else {
        const result = outcome.result;
        await postJson({ mode: 'tone_needs_review', snapshot_id: item.id, job_token: item.job_token, tone: result.tone, confidence: result.confidence, reason: result.reason, provider: result.provider, model: result.model, raw_json: rawJson });
        summary.needsReview += 1;
      }
    } catch (error) { await reportFailure('tone_failed', item, error); console.warn(`Tone failed #${item.id}: ${error.message}`); }
  }
}

async function writeSummary() {
  const lines = ['## Visual Tone Run', '', `- Scope: ${scope.run_mode}/${scope.stage}`, `- Logical website limit: ${scope.limit}`, `- Claimed: ${summary.claimed}; skipped exact targets: ${summary.skipped}`, '', '### Capture', `- Success: ${summary.captureSuccess}`, `- Blocked: ${summary.captureBlocked}`, `- Failed: ${summary.captureFailed}`, '', '### Analysis', `- Qwen accepted: ${summary.qwenAccepted}`, `- Gemini judged: ${summary.geminiJudged}`, `- Needs review: ${summary.needsReview}`, `- Free provider deferred: ${summary.providerDeferred}`, '', '- Policy: FREE_ONLY=true; no paid provider was selected.', ''].join('\n');
  console.log(lines);
  if (process.env.GITHUB_STEP_SUMMARY) await appendFile(process.env.GITHUB_STEP_SUMMARY, `${lines}\n`);
}

try {
  const config = await workerConfig();
  if ('schedule' === workflowEvent && 'auto' !== config.mode) {
    console.log('Visual Tone is in MANUAL mode; scheduled run exited without claiming work.');
  } else {
    const jobs = await claimJobs(scope);
    await processToneItems(jobs.filter((item) => item.stage === 'tone'), config.ai_strategy || 'smart', Number(config.auto_accept_threshold || 0.85));
    const capturedIds = await processCaptureItems(jobs.filter((item) => item.stage === 'capture'));
    // A full batch may analyze its own fresh capture, but never any other queue
    // item and never as a second logical website slot.
    if (capturedIds.length && (scope.run_mode === 'batch' || scope.stage === 'full')) {
      const freshToneJobs = await claimJobs({ run_mode: 'targeted', stage: 'tone', target_ids: capturedIds, limit: capturedIds.length });
      await processToneItems(freshToneJobs, config.ai_strategy || 'smart', Number(config.auto_accept_threshold || 0.85));
    }
  }
} finally {
  await writeSummary();
  await rm(workDir, { recursive: true, force: true });
}
