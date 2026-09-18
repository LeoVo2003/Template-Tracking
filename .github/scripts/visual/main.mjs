import { chromium } from 'playwright';
import { appendFile, mkdir, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { captureRenderedPage } from './capture.mjs';
import { classifyTone } from './classify.mjs';
import { fullRunContinuationTargets, normalizeJobScope } from './job-scope.mjs';
import { PageValidationError } from './validate-page.mjs';

const siteUrl = String(process.env.MAC_TRACKER_SITE_URL || '').replace(/\/+$/, '');
const secret = String(process.env.MAC_TRACKER_AUTOMATION_SECRET || '');
const scope = normalizeJobScope({ run_mode: process.env.RUN_MODE, stage: process.env.VISUAL_STAGE, target_ids: process.env.TARGET_IDS, limit: process.env.BATCH_LIMIT });
const sourceAction = String(process.env.SOURCE_ACTION || 'run_batch_now');
const cloudflareAccount = String(process.env.CLOUDFLARE_ACCOUNT_ID || '');
const cloudflareToken = String(process.env.CLOUDFLARE_API_TOKEN || '');
const groqApiKey = String(process.env.GROQ_API_KEY || '');
const geminiApiKey = String(process.env.GEMINI_API_KEY || '');
const geminiApiKeys = [String(process.env.GEMINI_API_KEY_1 || geminiApiKey || ''), String(process.env.GEMINI_API_KEY_2 || '')].filter(Boolean);
const freeOnly = 'false' !== String(process.env.FREE_ONLY || 'true').trim().toLowerCase();
const workflowEvent = String(process.env.WORKFLOW_EVENT || 'workflow_dispatch');
const githubRunId = String(process.env.GITHUB_RUN_ID || '').trim();
const githubRunAttempt = Number(process.env.GITHUB_RUN_ATTEMPT || 1) || 1;
const githubRepository = String(process.env.GITHUB_REPOSITORY || 'LeoVo2003/Template-Tracking');
const apiBase = `${siteUrl}/wp-json/mac-tracker/v1/visual`;
const workDir = join(process.cwd(), '.visual-capture');
const summary = { claimed: 0, skipped: 0, captureSuccess: 0, captureBlocked: 0, captureFailed: 0, qwenAccepted: 0, geminiJudged: 0, needsReview: 0, providerDeferred: 0, processed: 0, success: 0, providerCounts: {} };

if (!siteUrl || !secret) throw new Error('MAC_TRACKER_SITE_URL and MAC_TRACKER_AUTOMATION_SECRET are required.');
if (!freeOnly) throw new Error('FREE_ONLY must be true. This workflow is prohibited from selecting a paid provider.');

const headers = { 'X-MAC-Tracker-Automation': secret, 'User-Agent': 'MAC-Project-Tracker-GitHub-Action/3.0.0', Accept: 'application/json' };
await mkdir(workDir, { recursive: true });

async function claimJobs(requestedScope, { continuation = false } = {}) {
  const response = await fetch(`${apiBase}/jobs/claim`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(requestedScope) });
  if (!response.ok) throw new Error(`Scoped job claim failed: HTTP ${response.status} ${(await response.text()).replace(/\s+/g, ' ').slice(0, 700)}`);
  const payload = await response.json();
  // A full-run continuation is work for the same logical websites. It must not
  // inflate the batch count or turn a race into a misleading skipped total.
  if (!continuation) {
    summary.claimed += (payload.claimed_ids || []).length;
    summary.skipped += (payload.skipped_ids || []).length;
  }
  await reportRunHeartbeat({ current_step: 'claiming_targets', message: `${(payload.claimed_ids || []).length} targets claimed`, event_type: 'targets_claimed' });
  return payload.items || [];
}

/** Queue only this run's freshly persisted captures for the tone continuation. */
async function promoteFreshCaptures(capturedIds) {
  const response = await fetch(`${apiBase}/jobs/promote-captures`, {
    method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' },
    body: JSON.stringify({ target_ids: capturedIds }),
  });
  if (!response.ok) throw new Error(`Fresh-capture promotion failed: HTTP ${response.status} ${(await response.text()).replace(/\s+/g, ' ').slice(0, 700)}`);
  const payload = await response.json();
  return payload.promoted_ids || [];
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

function runPayload(fields = {}) {
  return {
    github_run_id: githubRunId, github_run_attempt: githubRunAttempt, source_action: sourceAction,
    run_mode: scope.run_mode, stage: scope.stage, target_ids: scope.target_ids, target_count: summary.claimed || scope.target_ids.length,
    processed_count: summary.processed, success_count: summary.success,
    failed_count: summary.captureFailed + summary.captureBlocked, needs_review_count: summary.needsReview,
    skipped_count: summary.skipped, capture_count: summary.captureSuccess, analysis_count: summary.qwenAccepted + summary.geminiJudged + summary.needsReview + summary.providerDeferred,
    provider_counts: summary.providerCounts, github_html_url: githubRunId ? `https://github.com/${githubRepository}/actions/runs/${githubRunId}` : '', ...fields,
  };
}

/** Observability must never make capture or classification fail. */
async function reportRunHeartbeat(fields = {}) {
  if (!githubRunId) return;
  try {
    const response = await fetch(`${apiBase}/run-heartbeat`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(runPayload(fields)) });
    if (!response.ok) console.warn(`Workflow heartbeat ignored: HTTP ${response.status}`);
  } catch (error) { console.warn(`Workflow heartbeat ignored: ${error.message}`); }
}

async function reportRunComplete(fields = {}) {
  if (!githubRunId) return;
  try {
    const response = await fetch(`${apiBase}/run-complete`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(runPayload({ status: 'completed', current_step: 'completed', ...fields })) });
    if (!response.ok) console.warn(`Workflow completion telemetry ignored: HTTP ${response.status}`);
  } catch (error) { console.warn(`Workflow completion telemetry ignored: ${error.message}`); }
}

function countProvider(provider) { const key = String(provider || 'unknown').toLowerCase(); summary.providerCounts[key] = (summary.providerCounts[key] || 0) + 1; }

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
        await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'capturing', message: `Capturing #${item.id}`, event_type: 'capture_started' });
        const captured = await captureRenderedPage(browser, item.website_url, item.id, item.job_token);
        await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'capture_uploading', message: `Uploading capture #${item.id}`, event_type: 'capture_uploading' });
        await uploadCapture(item, captured);
        capturedIds.push(item.id);
        summary.captureSuccess += 1;
        if (scope.stage === 'capture') { summary.processed += 1; summary.success += 1; }
        await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'capturing', message: `Captured #${item.id}`, event_type: 'capture_success' });
        console.log(`Captured bundle #${item.id}: ${captured.bundle.http_status} ${captured.bundle.final_url}`);
      } catch (error) {
        await reportFailure('capture_failed', item, error);
        if (['CF_CHALLENGE', 'CAPTCHA', 'PARKED_DOMAIN', 'MAINTENANCE', 'LOGIN_WALL', 'BAD_REDIRECT', 'EMPTY_PAGE'].includes(error.code)) summary.captureBlocked += 1;
        else summary.captureFailed += 1;
        summary.processed += 1;
        await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'failed', message: `Capture failed #${item.id}`, event_type: 'capture_failed' });
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

async function processToneItems(items, aiStrategy, autoAccept, geminiDailyBudgetPerKey) {
  if ('off' === aiStrategy) { console.log('AI strategy is OFF; no saved screenshots were submitted for analysis.'); return; }
  for (const item of items) {
    try {
      await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'analysis_preparing', message: `Preparing analysis for #${item.id}`, event_type: 'analysis_started' });
      const { bundle, preview } = await downloadPreview(item);
      const evidence = bundle?.ui?.metrics || { text: 'Capture bundle has no deterministic UI metrics.' };
      await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'qwen_analyzing', current_provider: 'qwen', message: `Qwen analyzing #${item.id}`, event_type: 'qwen_started' });
      const outcome = await classifyTone({ previewBuffer: preview, evidence, groqApiKey, geminiApiKey, geminiApiKeys, geminiDailyBudgetPerKey, cloudflareAccount, cloudflareToken, freeOnly, autoAccept, onProviderStep: ({ step, provider }) => reportRunHeartbeat({ current_snapshot_id: item.id, current_step: step, current_provider: provider, message: `${String(provider).replace(/_/g, ' ')} working on #${item.id}`, event_type: step }) });
      const rawJson = JSON.stringify({ phase: 4, ...outcome, deterministic: evidence });
      if ('classified' === outcome.state) {
        const result = outcome.result;
        await postJson({ mode: 'tone', snapshot_id: item.id, job_token: item.job_token, tone: result.tone_group, precise_tone: result.precise_tone, tone_group: result.tone_group, confidence: result.confidence, reason: result.reason, provider: result.provider, model: result.model, needs_review: result.needs_review, raw_json: rawJson });
        if ('gemini' === result.provider) summary.geminiJudged += 1; else summary.qwenAccepted += 1;
        countProvider(result.provider); summary.processed += 1; summary.success += 1;
        await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'saving_result', current_provider: result.provider, message: `Saved #${item.id} · ${result.tone_group}`, event_type: 'tone_saved' });
        console.log(`Classified #${item.id} with ${result.provider}/${result.model}: ${result.tone} @ ${result.confidence}`);
      } else if ('retry_wait' === outcome.state) {
        const quota = 'FREE_QUOTA_EXHAUSTED' === outcome.retry_code;
        await postJson({ mode: 'tone_retry', snapshot_id: item.id, job_token: item.job_token, error_code: outcome.retry_code, message: quota ? 'All configured free visual-tone providers are quota limited. No paid provider was used.' : 'All configured free visual-tone providers are temporarily unavailable. No paid provider was used.', raw_json: rawJson, retry_after_seconds: quota ? 1800 : 900 });
        summary.providerDeferred += 1;
        await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'waiting_provider', message: `Waiting for a free provider for #${item.id}`, event_type: 'retry_wait' });
      } else {
        const result = outcome.result;
        await postJson({ mode: 'tone_needs_review', snapshot_id: item.id, job_token: item.job_token, tone: result.tone_group, precise_tone: result.precise_tone, tone_group: result.tone_group, confidence: result.confidence, reason: result.reason, provider: result.provider, model: result.model, raw_json: rawJson });
        summary.needsReview += 1;
        countProvider(result.provider); summary.processed += 1;
        await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'saving_result', current_provider: result.provider, message: `#${item.id} needs review`, event_type: 'needs_review' });
      }
    } catch (error) { await reportFailure('tone_failed', item, error); summary.processed += 1; await reportRunHeartbeat({ current_snapshot_id: item.id, current_step: 'failed', message: `Analysis failed #${item.id}`, event_type: 'item_failed' }); console.warn(`Tone failed #${item.id}: ${error.message}`); }
  }
}

async function writeSummary() {
  const lines = ['## Visual Tone Action', '', `- Action: ${sourceAction}`, `- Scope: ${scope.run_mode}/${scope.stage}`, `- Logical website limit: ${scope.limit}`, `- Claimed logical websites: ${summary.claimed}; skipped exact targets: ${summary.skipped}`, `- Capture operations: ${summary.captureSuccess + summary.captureBlocked + summary.captureFailed}`, `- Analysis operations: ${summary.qwenAccepted + summary.geminiJudged + summary.needsReview + summary.providerDeferred}`, '', '### Capture', `- Success: ${summary.captureSuccess}`, `- Blocked: ${summary.captureBlocked}`, `- Failed: ${summary.captureFailed}`, '', '### Analysis', `- Qwen accepted: ${summary.qwenAccepted}`, `- Gemini judged: ${summary.geminiJudged}`, `- Needs review: ${summary.needsReview}`, `- Free provider deferred: ${summary.providerDeferred}`, '', '- Policy: FREE_ONLY=true; no paid provider was selected.', ''].join('\n');
  console.log(lines);
  if (process.env.GITHUB_STEP_SUMMARY) await appendFile(process.env.GITHUB_STEP_SUMMARY, `${lines}\n`);
}

let workerFailure = null;
try {
  await reportRunHeartbeat({ status: 'in_progress', current_step: 'starting', message: `Visual Tone ${sourceAction} started`, event_type: 'run_started' });
  const config = await workerConfig();
  if ('schedule' === workflowEvent && 'auto' !== config.mode) {
    console.log('Visual Tone is in MANUAL mode; scheduled run exited without claiming work.');
  } else {
    const jobs = await claimJobs(scope);
    const geminiDailyBudgetPerKey = Number(config.gemini_daily_budget_per_key || 8);
    await processToneItems(jobs.filter((item) => item.stage === 'tone'), config.ai_strategy || 'smart', Number(config.auto_accept_threshold || 0.85), geminiDailyBudgetPerKey);
    const capturedIds = await processCaptureItems(jobs.filter((item) => item.stage === 'capture'));
    // Full mode alone continues a successful capture into tone analysis. It is
    // explicitly scoped to the returned IDs, so it cannot drain another run's
    // stored-analysis queue or inflate the logical website summary.
    if (capturedIds.length && scope.stage === 'full') {
      const promotedIds = await promoteFreshCaptures(capturedIds);
      const freshToneIds = fullRunContinuationTargets(scope.stage, capturedIds, promotedIds);
      if (freshToneIds.length) {
        const freshToneJobs = await claimJobs({ run_mode: 'targeted', stage: 'tone', target_ids: freshToneIds, limit: freshToneIds.length }, { continuation: true });
        await processToneItems(freshToneJobs, config.ai_strategy || 'smart', Number(config.auto_accept_threshold || 0.85), geminiDailyBudgetPerKey);
      }
    }
  }
} catch (error) {
  workerFailure = error;
  throw error;
} finally {
  await writeSummary();
  await reportRunComplete({ current_step: workerFailure ? 'failed' : 'completed', message: workerFailure ? `Worker failed: ${workerFailure.message}` : 'Visual Tone run completed.' });
  await rm(workDir, { recursive: true, force: true });
}
