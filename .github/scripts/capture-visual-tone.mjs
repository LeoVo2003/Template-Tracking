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
const tones = ['Vàng kem sáng', 'Đen vàng', 'Hồng xanh trắng', 'Hồng trắng', 'Đỏ trắng', 'Nâu kem', 'Xanh trắng', 'Xanh đen', 'Đen trắng', 'Đỏ hồng', 'Tím hồng', 'Cần duyệt'];

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

function colorEvidence(raw) {
  try {
    const variables = JSON.parse(String(raw || ''))?.variables || {};
    return Object.entries(variables)
      .filter(([name, value]) => /^--e-global-color-(primary|secondary|text|accent)$/i.test(name) && /^#[0-9a-f]{6}$/i.test(String(value).trim()))
      .map(([name, value]) => `${name}=${String(value).trim().toUpperCase()}`);
  } catch { return []; }
}

async function liveColorEvidence(page) {
  return page.evaluate(() => ['primary', 'secondary', 'text', 'accent'].flatMap((role) => {
    const name = `--e-global-color-${role}`;
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return /^#[0-9a-f]{6}$/i.test(value) ? [`${name}=${value.toUpperCase()}`] : [];
  }));
}

function tonePrompt(colors) {
  const evidence = colors.length ? colors.join(', ') : 'No Elementor global colors available; rely on the rendered screenshot.';
  return `Classify a rendered nail salon website screenshot into one fixed visual tone. Prioritize the website UI palette: large header/footer bars, section backgrounds, buttons, borders and repeated typography accents. Treat photos of hands, nails, flowers or products as content, not the brand palette. Use Elementor global variables only as supporting evidence, never as a replacement for the rendered page. Global color evidence: ${evidence}. Choose exactly one label: ${tones.join(', ')}. Key meanings: Vàng kem sáng = pale yellow/cream dominant and light page; Đen vàng = dark/black dominant with gold/yellow accent; Hồng xanh trắng = pink and green UI accents on a mainly white/light page; Hồng trắng = pink UI dominant on a mainly white/light page; Đỏ trắng = red UI dominant on a mainly white/light page, even if photos contain pink nails. Return JSON only: {"tone":"one allowed label","confidence":"high|medium|low","reason":"one short Vietnamese sentence mentioning dominant UI colors and light/dark"}.`;
}

async function renderedColorEvidence(imageBuffer) {
  const { data, info } = await sharp(imageBuffer).resize({ width: 160, withoutEnlargement: true }).removeAlpha().raw().toBuffer({ resolveWithObject: true });
  const buckets = { blue: 0, green: 0, pink: 0, red: 0, yellow: 0 };
  let lightness = 0;
  let colourful = 0;
  for (let index = 0; index < data.length; index += info.channels) {
    const r = data[index] / 255;
    const g = data[index + 1] / 255;
    const b = data[index + 2] / 255;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const delta = max - min;
    const value = max;
    lightness += (max + min) / 2;
    if (delta < 0.12 || value < 0.12) continue;
    let hue = 0;
    if (max === r) hue = 60 * (((g - b) / delta + 6) % 6);
    if (max === g) hue = 60 * ((b - r) / delta + 2);
    if (max === b) hue = 60 * ((r - g) / delta + 4);
    const weight = delta * (0.35 + value);
    colourful += weight;
    if (hue >= 180 && hue < 275) buckets.blue += weight;
    else if (hue >= 75 && hue < 180) buckets.green += weight;
    else if (hue >= 300 && hue < 340) buckets.pink += weight;
    else if (hue >= 340 || hue < 18) buckets.red += weight;
    else if (hue >= 35 && hue < 75) buckets.yellow += weight;
  }
  const count = data.length / info.channels;
  const share = (name) => colourful ? buckets[name] / colourful : 0;
  const brightness = lightness / count;
  return {
    brightness,
    blue: share('blue'),
    green: share('green'),
    pink: share('pink'),
    red: share('red'),
    yellow: share('yellow'),
    text: `Rendered-pixel measurement: ${brightness >= 0.62 ? 'mainly light' : brightness <= 0.42 ? 'mainly dark' : 'mixed brightness'}; blue/cyan ${Math.round(share('blue') * 100)}%, green ${Math.round(share('green') * 100)}%, pink ${Math.round(share('pink') * 100)}%, red ${Math.round(share('red') * 100)}%, yellow/gold ${Math.round(share('yellow') * 100)}% of saturated visible pixels. This measurement supports the rendered UI over a conflicting declared CSS variable.`,
  };
}

function reconcileTone(tone, evidence) {
  const light = evidence.brightness >= 0.62;
  if (light && evidence.red >= 0.14 && evidence.red > evidence.pink * 1.3 && ['Hồng trắng', 'Đỏ hồng', 'Hồng xanh trắng'].includes(tone)) return 'Đỏ trắng';
  if (light && evidence.blue >= 0.22 && evidence.blue > (evidence.pink + evidence.red) * 1.3 && ['Hồng trắng', 'Đỏ hồng', 'Hồng xanh trắng', 'Đỏ trắng'].includes(tone)) return 'Xanh trắng';
  if (light && evidence.yellow >= 0.24 && evidence.yellow > (evidence.pink + evidence.red) * 1.25 && ['Hồng trắng', 'Đỏ hồng', 'Đỏ trắng'].includes(tone)) return 'Vàng kem sáng';
  if (evidence.brightness <= 0.42 && evidence.yellow >= 0.16 && tone !== 'Đen vàng') return 'Đen vàng';
  return tone;
}

function parseTone(response, evidence) {
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
  const normalized = text.toLocaleLowerCase('vi-VN');
  const explicitTone = tones.find((tone) => normalized.includes(tone.toLocaleLowerCase('vi-VN')));
  // Vision occasionally describes the palette in English even when asked for
  // a Vietnamese fixed label. Only map clear two-colour combinations.
  const englishTone = [
    [/\b(pink|hồng)\b[\s\S]{0,180}\b(green|xanh)\b[\s\S]{0,180}\b(white|trắng)\b|\b(green|xanh)\b[\s\S]{0,180}\b(pink|hồng)\b[\s\S]{0,180}\b(white|trắng)\b/, 'Hồng xanh trắng'],
    [/\b(red|đỏ)\b[\s\S]{0,180}\b(white|trắng)\b|\b(white|trắng)\b[\s\S]{0,180}\b(red|đỏ)\b/, 'Đỏ trắng'],
    [/\b(pink|hồng)\b[\s\S]{0,180}\b(white|trắng)\b|\b(white|trắng)\b[\s\S]{0,180}\b(pink|hồng)\b/, 'Hồng trắng'],
    [/\b(red|đỏ)\b[\s\S]{0,180}\b(pink|hồng)\b|\b(pink|hồng)\b[\s\S]{0,180}\b(red|đỏ)\b/, 'Đỏ hồng'],
    [/\b(purple|tím)\b[\s\S]{0,180}\b(pink|hồng)\b|\b(pink|hồng)\b[\s\S]{0,180}\b(purple|tím)\b/, 'Tím hồng'],
    [/\b(brown|nâu)\b[\s\S]{0,180}\b(cream|kem)\b|\b(cream|kem)\b[\s\S]{0,180}\b(brown|nâu)\b/, 'Nâu kem'],
  ].find(([pattern]) => pattern.test(normalized))?.[1];
  const candidateTone = tones.includes(parsed.tone) ? parsed.tone : (explicitTone || englishTone || '');
  const tone = reconcileTone(candidateTone, evidence);
  if (!tone) throw new Error(`Llama Vision returned no supported tone: ${text.replace(/\s+/g, ' ').slice(0, 500)}`);
  const confidenceMatch = text.match(/\b(high|medium|low)\b/i);
  return {
    tone,
    confidence: ['high', 'medium', 'low'].includes(parsed.confidence) ? parsed.confidence : (confidenceMatch ? confidenceMatch[1].toLowerCase() : 'low'),
    reason: tone !== candidateTone ? `Screenshot pixel check corrected the AI response: ${evidence.text}` : String(parsed.reason || text.replace(/\s+/g, ' ').slice(0, 500)).slice(0, 500),
  };
}

async function classify(snapshotId, imageBuffer, colors = []) {
  if (!cloudflareAccount || !cloudflareToken) return { skipped: true };
  await postJson({ mode: 'tone_started', snapshot_id: snapshotId });
  const evidence = await renderedColorEvidence(imageBuffer);
  const response = await fetch(`https://api.cloudflare.com/client/v4/accounts/${cloudflareAccount}/ai/run/@cf/meta/llama-3.2-11b-vision-instruct`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${cloudflareToken}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ prompt: `${tonePrompt(colors)}\n${evidence.text}`, image: `data:image/jpeg;base64,${imageBuffer.toString('base64')}`, max_tokens: 160, temperature: 0.1 }),
  });
  if (response.status === 429) return { skipped: true, quota: true };
  if (!response.ok) throw new Error(`Llama Vision failed: HTTP ${response.status} ${await response.text()}`);
  const tone = parseTone(await response.json(), evidence);
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
        await postJson({ mode: 'capture_started', snapshot_id: item.id });
        await page.goto(item.website_url, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.evaluate(() => {
          const copy = (element, target, sources) => {
            if (element.getAttribute(target)) return;
            for (const source of sources) {
              const value = element.getAttribute(source);
              if (value) { element.setAttribute(target, value); break; }
            }
          };
          document.querySelectorAll('img').forEach((image) => {
            image.loading = 'eager';
            copy(image, 'src', ['data-src', 'data-lazy-src', 'data-original', 'data-e-src']);
            copy(image, 'srcset', ['data-srcset', 'data-lazy-srcset', 'data-e-srcset']);
          });
          document.querySelectorAll('[data-bg], [data-background-image], [data-lazy-bg]').forEach((element) => {
            const source = element.getAttribute('data-bg') || element.getAttribute('data-background-image') || element.getAttribute('data-lazy-bg');
            if (source && !getComputedStyle(element).backgroundImage.includes('url(')) element.style.backgroundImage = `url("${source}")`;
          });
        });
        // Full-page screenshots do not automatically activate below-the-fold
        // lazy assets or scroll-triggered sections. Walk the document first.
        let lastHeight = 0;
        // Four deliberate passes give slow Elementor/lazy image observers time
        // to fetch sections that only mount after they enter the viewport.
        for (let pass = 0; pass < 4; pass += 1) {
          const height = await page.evaluate(() => Math.max(document.body.scrollHeight, document.documentElement.scrollHeight));
          for (let y = 0; y < height; y += 720) {
            await page.evaluate((top) => window.scrollTo(0, top), y);
            await page.waitForTimeout(280);
          }
          if (height === lastHeight) break;
          lastHeight = height;
        }
        const colors = await liveColorEvidence(page);
        await page.evaluate(() => window.scrollTo(0, 0));
        // Let transitions, background images and deferred sections settle after
        // returning to the top. This is intentionally separate from <img> load.
        await page.waitForTimeout(2500);
        await page.evaluate(() => Promise.race([
          Promise.all([...document.images].map((image) => image.complete ? Promise.resolve() : new Promise((resolve) => { image.addEventListener('load', resolve, { once: true }); image.addEventListener('error', resolve, { once: true }); }))),
          new Promise((resolve) => setTimeout(resolve, 12000)),
        ]));
        await page.screenshot({ path: file, fullPage: true, type: 'jpeg', quality: 55 });
        const full = await readFile(file);
        const form = new FormData();
        form.append('mode', 'capture');
        form.append('snapshot_id', String(item.id));
        form.append('screenshot', new Blob([full], { type: 'image/jpeg' }), `mac-tracker-${item.id}.jpg`);
        await ingest(form);
        const preview = await sharp(full).resize({ width: 768, withoutEnlargement: true }).jpeg({ quality: 62 }).toBuffer();
        try {
          await classify(item.id, preview, colors.length ? colors : colorEvidence(item.color_source_raw));
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
      const result = await classify(item.id, preview, colorEvidence(item.color_source_raw));
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
