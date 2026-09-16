import { chromium } from 'playwright';
import sharp from 'sharp';
import { mkdir, readFile, rm } from 'node:fs/promises';
import { join } from 'node:path';

const siteUrl = String(process.env.MAC_TRACKER_SITE_URL || '').replace(/\/+$/, '');
const secret = String(process.env.MAC_TRACKER_AUTOMATION_SECRET || '');
const limit = Math.max(1, Math.min(25, Number(process.env.BATCH_LIMIT || 10)));
const cloudflareAccount = String(process.env.CLOUDFLARE_ACCOUNT_ID || '');
const cloudflareToken = String(process.env.CLOUDFLARE_API_TOKEN || '');
const apiBase = `${siteUrl}/wp-json/mac-tracker/v1/visual`;
const workDir = join(process.cwd(), '.visual-capture');
const tones = ['Vàng đen', 'Đỏ hồng', 'Hồng trắng', 'Nâu kem', 'Xanh trắng', 'Xanh đen', 'Đen trắng', 'Tím hồng', 'Cần duyệt'];

if (!siteUrl || !secret) throw new Error('MAC_TRACKER_SITE_URL and MAC_TRACKER_AUTOMATION_SECRET are required.');

// Explicitly identify the cloud job. Some WordPress firewalls reject Node's
// default fetch signature before the request reaches our private REST route.
const headers = {
  'X-MAC-Tracker-Automation': secret,
  'User-Agent': 'MAC-Project-Tracker-GitHub-Action/0.12.1',
  'Accept': 'application/json',
};
await mkdir(workDir, { recursive: true });

async function queue(stage) {
  const response = await fetch(`${apiBase}/queue?stage=${stage}&limit=${limit}`, { headers });
  if (!response.ok) {
    const detail = (await response.text()).replace(/\s+/g, ' ').slice(0, 700);
    throw new Error(`Queue ${stage} failed: HTTP ${response.status}${detail ? ` — ${detail}` : ''}`);
  }
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

function tonePrompt() {
  return `You classify the visual tone of a rendered nail salon website screenshot. Do not infer from CSS variables or text alone; use the visible hero, headers, large section backgrounds, buttons and dominant accents. Choose exactly one tone from: ${tones.join(', ')}. Return JSON only: {"tone":"one allowed label","confidence":"high|medium|low","reason":"one short Vietnamese sentence"}. Use "Cần duyệt" whenever no tone clearly dominates.`;
}

function parseTone(response) {
  // Workers AI usually places text at result.response, but the model is not
  // guaranteed to obey JSON-only output. Preserve the fixed label list as a
  // safe fallback instead of throwing away an otherwise useful answer.
  const value = response?.result?.response ?? response?.response ?? response?.result ?? response;
  const text = typeof value === 'string' ? value : JSON.stringify(value);
  const match = text.match(/\{[\s\S]*\}/);
  let parsed = {};
  if (match) {
    try { parsed = JSON.parse(match[0]); } catch { parsed = {}; }
  }
  const explicitTone = tones.find((tone) => text.toLocaleLowerCase('vi-VN').includes(tone.toLocaleLowerCase('vi-VN')));
  const tone = tones.includes(parsed.tone) ? parsed.tone : (explicitTone || '');
  if (!tone) throw new Error(`Llama Vision returned no supported tone: ${text.replace(/\s+/g, ' ').slice(0, 500)}`);
  const confidenceMatch = text.match(/\b(high|medium|low)\b/i);
  return {
    tone,
    confidence: ['high', 'medium', 'low'].includes(parsed.confidence) ? parsed.confidence : (confidenceMatch ? confidenceMatch[1].toLowerCase() : 'low'),
    reason: String(parsed.reason || text.replace(/\s+/g, ' ').slice(0, 500)).slice(0, 500),
  };
}

async function classify(snapshotId, imageBuffer) {
  if (!cloudflareAccount || !cloudflareToken) return { skipped: true };
  const response = await fetch(`https://api.cloudflare.com/client/v4/accounts/${cloudflareAccount}/ai/run/@cf/meta/llama-3.2-11b-vision-instruct`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${cloudflareToken}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ prompt: tonePrompt(), image: `data:image/jpeg;base64,${imageBuffer.toString('base64')}`, max_tokens: 120, temperature: 0.1 }),
  });
  if (response.status === 429) return { skipped: true, quota: true };
  if (!response.ok) throw new Error(`Llama Vision failed: HTTP ${response.status} ${await response.text()}`);
  const tone = parseTone(await response.json());
  await postJson({ mode: 'tone', snapshot_id: snapshotId, ...tone });
  return tone;
}

async function captureBatch() {
  const items = await queue('capture');
  if (!items.length) return 0;
  const browser = await chromium.launch({ headless: true });
  try {
    for (const item of items) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
      const file = join(workDir, `snapshot-${item.id}.jpg`);
      try {
        await page.goto(item.website_url, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(1800);
        await page.screenshot({ path: file, fullPage: true, type: 'jpeg', quality: 55 });
        const full = await readFile(file);
        const form = new FormData();
        form.append('mode', 'capture');
        form.append('snapshot_id', String(item.id));
        form.append('screenshot', new Blob([full], { type: 'image/jpeg' }), `mac-tracker-${item.id}.jpg`);
        await ingest(form);
        const preview = await sharp(full).resize({ width: 768, withoutEnlargement: true }).jpeg({ quality: 62 }).toBuffer();
        try {
          await classify(item.id, preview);
        } catch (error) {
          await postJson({ mode: 'tone_failed', snapshot_id: item.id, message: error.message });
          console.warn(`Tone failed #${item.id}: ${error.message}`);
        }
        console.log(`Captured #${item.id}`);
      } catch (error) {
        await postJson({ mode: 'capture_failed', snapshot_id: item.id, message: error.message });
        console.warn(`Capture failed #${item.id}: ${error.message}`);
      } finally {
        await page.close();
        await rm(file, { force: true });
      }
    }
  } finally {
    await browser.close();
  }
  return items.length;
}

async function classifyPendingBatch() {
  if (!cloudflareAccount || !cloudflareToken) return 0;
  const items = await queue('tone');
  for (const item of items) {
    try {
      const response = await fetch(item.screenshot_url);
      if (!response.ok) throw new Error(`Screenshot download failed: HTTP ${response.status}`);
      const preview = await sharp(Buffer.from(await response.arrayBuffer())).resize({ width: 768, withoutEnlargement: true }).jpeg({ quality: 62 }).toBuffer();
      const result = await classify(item.id, preview);
      if (result.quota) { console.log('Workers AI daily quota reached; remaining tone jobs stay queued.'); break; }
      console.log(`Classified #${item.id}: ${result.tone}`);
    } catch (error) {
      await postJson({ mode: 'tone_failed', snapshot_id: item.id, message: error.message });
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
