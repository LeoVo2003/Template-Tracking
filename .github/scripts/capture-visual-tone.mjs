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
const tones = ['Vàng kem sáng', 'Vàng đen', 'Vàng trắng', 'Đen vàng', 'Hồng xanh trắng', 'Hồng trắng', 'Hồng đen', 'Đỏ trắng', 'Đỏ hồng', 'Đỏ đen', 'Nâu kem', 'Nâu trắng', 'Nâu vàng', 'Nâu đen', 'Xanh vàng', 'Xanh trắng', 'Xanh đen', 'Xanh kem', 'Đen trắng', 'Trắng kem', 'Tím hồng', 'Tím trắng', 'Tím đen', 'Cam trắng', 'Cam đen', 'Cần duyệt'];

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
  const response = await fetch(`${apiBase}/queue`, {
    method: 'POST',
    headers: { ...headers, 'Content-Type': 'application/json' },
    body: JSON.stringify({ stage, limit }),
  });
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

function tonePrompt(evidence) {
  return `Classify the website into one fixed visual tone. The supplied image has photos/media and CSS background images hidden, so judge the rendered UI only. Use this decision order: (1) identify the repeated structural hue across sections, header/footer bars, buttons, borders and navigation; (2) pair it with the repeated structural surface—white, cream, gold, or dark; (3) only then use the supplied 20% screenshot context as a tie-breaker. UI palette is 80% of the decision.

The evidence percentages are absolute visible UI coverage, not shares inside only saturated pixels. A minor logo, one button, border, icon, black body text, or any nail/skin/flower/lipstick/product photo must not decide the label. Do not call a site red unless true saturated red is repeated across meaningful UI surfaces (normally about 5% or more of the measured UI). Pale rose is pink; beige/taupe/nude is brown or cream; warm gold is yellow/gold—not red or pink. Do not call a site dark/black from text alone: require substantial dark UI sections.

Weighted evidence: ${evidence.text}

Choose exactly one label: ${tones.join(', ')}.
Pair meanings: Vàng kem sáng = light cream/yellow UI; Vàng đen = yellow/gold with black UI; Vàng trắng = yellow/gold with white UI; Đen vàng = dark UI with gold/yellow accents. Hồng xanh trắng = pink and green accents on a light UI; Hồng trắng = pink/rose UI on a light surface; Hồng đen = pink with substantial black sections. Đỏ trắng/Đỏ hồng/Đỏ đen require repeated true red, paired with white, pink, or black. Nâu kem/Nâu trắng/Nâu vàng/Nâu đen cover taupe, beige, nude and brown paired with cream, white, gold, or black. Xanh vàng/Xanh trắng/Xanh đen/Xanh kem cover green or blue paired with gold, white, black, or cream. Đen trắng = neutral black/white UI; Trắng kem = mostly white and cream UI. Tím hồng/Tím trắng/Tím đen and Cam trắng/Cam đen follow the same structural pair rule. If two labels remain plausible, the leading hue is small, or the UI is neutral without a clear pair, choose Cần duyệt.

Return JSON only: {"tone":"one allowed label","confidence":"high|medium|low","reason":"one short Vietnamese sentence about the measured UI palette"}.`;
}

function parseRgb(value) {
  const match = String(value || '').match(/rgba?\(\s*(\d+(?:\.\d+)?)\s*[, ]\s*(\d+(?:\.\d+)?)\s*[, ]\s*(\d+(?:\.\d+)?)(?:\s*[,/]\s*(\d+(?:\.\d+)?))?/i);
  if (!match) return null;
  return { r: Number(match[1]), g: Number(match[2]), b: Number(match[3]), a: match[4] === undefined ? 1 : Number(match[4]) };
}

function rgbToHsl({ r, g, b }) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b), delta = max - min;
  let h = 0;
  if (delta) {
    if (max === r) h = 60 * (((g - b) / delta + 6) % 6);
    else if (max === g) h = 60 * ((b - r) / delta + 2);
    else h = 60 * ((r - g) / delta + 4);
  }
  const l = (max + min) / 2;
  const s = delta ? delta / (1 - Math.abs(2 * l - 1)) : 0;
  return { h, s, l };
}

function uiCategory(rgb) {
  const { h, s, l } = rgbToHsl(rgb);
  if (l <= 0.22) return 'dark';
  if (s <= 0.12) return l >= 0.72 ? 'light' : (l <= 0.38 ? 'dark' : 'neutral');
  if (l >= 0.82 && s <= 0.38 && (h < 70 || h >= 330)) return 'cream';
  if ((h < 14 || h >= 350) && l < 0.62 && s >= 0.32) return 'red';
  if ((h >= 315 || h < 14) && l >= 0.56) return 'pink';
  if (h >= 315 && h < 350) return 'pink';
  if (h >= 255 && h < 315) return 'purple';
  if (h >= 165 && h < 255) return 'blue';
  if (h >= 72 && h < 165) return 'green';
  if (h >= 38 && h < 72) return 'yellow';
  if (h >= 14 && h < 38) return l < 0.76 ? 'brown' : 'cream';
  return l >= 0.72 ? 'light' : 'neutral';
}

function inferTone(c, dark, light, chromaRatio, cream = 0, coverage = {}) {
  // `c` is the mix inside only saturated pixels. Never use it by itself: a
  // 2% red button can otherwise become “21% red” and falsely label a whole UI.
  const share = (name) => Number.isFinite(coverage[name]) ? coverage[name] : (c[name] || 0) * chromaRatio;
  const red = share('red'), pink = share('pink'), brown = share('brown'), yellow = share('yellow');
  const greenBlue = share('green') + share('blue');
  // Dark body text is common on light sites. A dark tone must have enough
  // actual dark UI surface; otherwise this stays ambiguous for Llama review.
  if (chromaRatio < 0.045) return dark >= 0.56 ? 'Đen trắng' : (cream >= 0.23 ? 'Trắng kem' : 'Cần duyệt');
  if (dark >= 0.50 && yellow >= 0.035) return 'Đen vàng';
  if (dark >= 0.50 && greenBlue >= 0.045) return 'Xanh đen';
  if (dark >= 0.58 && chromaRatio < 0.11) return 'Đen trắng';
  if (yellow >= 0.06 && dark >= 0.38) return 'Vàng đen';
  if (pink >= 0.06 && dark >= 0.32) return 'Hồng đen';
  if (pink >= 0.045 && share('green') >= 0.028) return 'Hồng xanh trắng';
  if (brown >= 0.065 && c.brown >= 0.30) {
    if (dark >= 0.34) return 'Nâu đen';
    if (yellow >= 0.035) return 'Nâu vàng';
    return light >= 0.44 ? 'Nâu trắng' : 'Nâu kem';
  }
  if (red >= 0.05 && c.red >= 0.38) return dark >= 0.32 ? 'Đỏ đen' : 'Đỏ trắng';
  if (pink >= 0.04 && c.pink >= 0.30) return 'Hồng trắng';
  if (red >= 0.025 && pink >= 0.03) return 'Đỏ hồng';
  if (share('purple') >= 0.045 && dark >= 0.34) return 'Tím đen';
  if (share('purple') >= 0.045 && light >= 0.45) return 'Tím trắng';
  if (share('purple') >= 0.035 && pink >= 0.025) return 'Tím hồng';
  if (greenBlue >= 0.05 && yellow >= 0.025) return 'Xanh vàng';
  if (greenBlue >= 0.06) return cream >= 0.20 ? 'Xanh kem' : (light >= 0.34 ? 'Xanh trắng' : 'Xanh đen');
  if (yellow >= 0.055) return light >= 0.42 ? 'Vàng trắng' : 'Vàng kem sáng';
  if (cream >= 0.28) return 'Trắng kem';
  return 'Cần duyệt';
}

function summarizeUiSamples(samples) {
  const buckets = { red: 0, pink: 0, brown: 0, yellow: 0, green: 0, blue: 0, purple: 0, dark: 0, light: 0, cream: 0, neutral: 0 };
  const colors = new Map();
  let total = 0;
  for (const sample of samples) {
    const rgb = parseRgb(sample.color);
    const weight = Number(sample.weight || 0);
    if (!rgb || rgb.a <= 0.03 || weight <= 0) continue;
    const category = uiCategory(rgb);
    buckets[category] += weight;
    total += weight;
    const hex = `#${[rgb.r, rgb.g, rgb.b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('')}`.toUpperCase();
    colors.set(hex, (colors.get(hex) || 0) + weight);
  }
  const share = (name) => total ? buckets[name] / total : 0;
  const chromatic = ['red', 'pink', 'brown', 'yellow', 'green', 'blue', 'purple'];
  const chromaTotal = chromatic.reduce((sum, key) => sum + buckets[key], 0);
  const chromaShare = (name) => chromaTotal ? buckets[name] / chromaTotal : 0;
  const dark = share('dark');
  const light = share('light') + share('cream');
  const cream = share('cream');
  const c = Object.fromEntries(chromatic.map((key) => [key, chromaShare(key)]));
  const chromaRatio = chromaTotal / Math.max(total, 1);
  const coverage = Object.fromEntries(chromatic.map((key) => [key, share(key)]));
  const inferred = inferTone(c, dark, light, chromaRatio, cream, coverage);
  const ranked = [...colors.entries()].sort((a, b) => b[1] - a[1]).slice(0, 6).map(([hex]) => hex);
  const percent = (value) => Math.round(value * 100);
  const text = `Chỉ màu UI, đã loại ảnh: nền ${light >= dark ? 'sáng' : 'tối'}; diện tích UI nâu ${percent(coverage.brown)}%, hồng ${percent(coverage.pink)}%, đỏ thật ${percent(coverage.red)}%, xanh lá ${percent(coverage.green)}%, xanh dương ${percent(coverage.blue)}%, vàng ${percent(coverage.yellow)}%, tím ${percent(coverage.purple)}%; màu UI nổi bật ${ranked.join(', ') || 'không rõ'}.`;
  const leadingCoverage = Math.max(...Object.values(coverage));
  const certainty = inferred !== 'Cần duyệt' && (leadingCoverage >= 0.055 || (dark >= 0.32 && (coverage.yellow >= 0.028 || coverage.green + coverage.blue >= 0.035))) ? 'high' : (inferred !== 'Cần duyệt' ? 'medium' : 'low');
  return { ...c, dark, light, cream, chromaRatio, coverage, inferred, certainty, text };
}

async function screenshotColorEvidence(imageBuffer) {
  const { data, info } = await sharp(imageBuffer).resize({ width: 160, withoutEnlargement: true }).removeAlpha().raw().toBuffer({ resolveWithObject: true });
  const buckets = { red: 0, pink: 0, brown: 0, yellow: 0, green: 0, blue: 0, purple: 0, dark: 0, light: 0, cream: 0, neutral: 0 };
  let total = 0;
  for (let index = 0; index < data.length; index += info.channels) {
    const rgb = { r: data[index], g: data[index + 1], b: data[index + 2] };
    const category = uiCategory(rgb);
    buckets[category] += 1;
    total += 1;
  }
  const chromatic = ['red', 'pink', 'brown', 'yellow', 'green', 'blue', 'purple'];
  const chromaTotal = chromatic.reduce((sum, key) => sum + buckets[key], 0);
  const c = Object.fromEntries(chromatic.map((key) => [key, chromaTotal ? buckets[key] / chromaTotal : 0]));
  const coverage = Object.fromEntries(chromatic.map((key) => [key, total ? buckets[key] / total : 0]));
  return {
    ...c,
    dark: total ? buckets.dark / total : 0,
    light: total ? (buckets.light + buckets.cream) / total : 0,
    cream: total ? buckets.cream / total : 0,
    chromaRatio: total ? chromaTotal / total : 0,
    coverage,
  };
}

function blendVisualEvidence(ui, screenshot) {
  const keys = ['red', 'pink', 'brown', 'yellow', 'green', 'blue', 'purple'];
  const combined = Object.fromEntries(keys.map((key) => [key, ui[key] * 0.80 + screenshot[key] * 0.20]));
  const dark = ui.dark * 0.80 + screenshot.dark * 0.20;
  const light = ui.light * 0.80 + screenshot.light * 0.20;
  const cream = ui.cream * 0.80 + screenshot.cream * 0.20;
  const chromaRatio = ui.chromaRatio * 0.80 + screenshot.chromaRatio * 0.20;
  const coverage = Object.fromEntries(keys.map((key) => [key, (ui.coverage?.[key] || 0) * 0.80 + (screenshot.coverage?.[key] || 0) * 0.20]));
  const inferred = inferTone(combined, dark, light, chromaRatio, cream, coverage);
  const leadingCoverage = Math.max(...Object.values(coverage));
  const certainty = inferred !== 'Cần duyệt' && (leadingCoverage >= 0.055 || (dark >= 0.32 && (coverage.yellow >= 0.028 || coverage.green + coverage.blue >= 0.035))) ? 'high' : (inferred !== 'Cần duyệt' ? 'medium' : 'low');
  const percent = (value) => Math.round(value * 100);
  const text = `Trọng số 80% UI + 20% ảnh: diện tích nâu ${percent(coverage.brown)}%, hồng ${percent(coverage.pink)}%, đỏ thật ${percent(coverage.red)}%, xanh lá ${percent(coverage.green)}%, xanh dương ${percent(coverage.blue)}%, vàng ${percent(coverage.yellow)}%, tím ${percent(coverage.purple)}%. Ảnh chỉ là tín hiệu phụ và không được tự ghi đè UI rõ ràng.`;
  return { ...combined, dark, light, cream, chromaRatio, coverage, inferred, certainty, text };
}

async function renderedUiEvidence(browser, url) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(1400);
    const challenge = await page.evaluate(() => /performing security verification|verify you are human|checking your browser|just a moment\.\.\./i.test(`${document.title} ${document.body?.innerText || ''}`));
    if (challenge) throw new Error('Homepage is behind a Cloudflare security challenge; the real UI was not captured.');
    const samples = await page.evaluate(() => {
      const result = [];
      const rgba = (value) => /^rgba?\(/i.test(String(value || '')) && !/rgba\([^)]*,\s*0(?:\.0+)?\s*\)$/i.test(String(value || ''));
      const add = (color, weight, role) => { if (rgba(color) && weight > 0) result.push({ color, weight, role }); };
      const semantic = new Set(['HEADER', 'NAV', 'MAIN', 'SECTION', 'ARTICLE', 'FOOTER', 'BUTTON']);
      const nodes = [document.body, ...document.querySelectorAll('header,nav,main,section,article,footer,button,a,h1,h2,h3,h4,p,div,li,input,select,textarea,svg')];
      for (const el of nodes.slice(0, 6000)) {
        if (!el || el.matches?.('img,picture,video,canvas,iframe,source') || el.closest?.('picture,video')) continue;
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity || 1) <= 0.03 || rect.width < 2 || rect.height < 2) continue;
        const area = Math.min(rect.width * rect.height, innerWidth * 1400);
        const structural = semantic.has(el.tagName) ? 1.9 : 1;
        const parentBg = el.parentElement ? getComputedStyle(el.parentElement).backgroundColor : '';
        if (style.backgroundColor !== parentBg || semantic.has(el.tagName)) add(style.backgroundColor, Math.min(55, Math.sqrt(area) / 20) * structural, 'background');
        const hasOwnText = [...el.childNodes].some((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
        if (hasOwnText || /^H[1-4]$/.test(el.tagName) || ['A', 'BUTTON'].includes(el.tagName)) {
          const textWeight = Math.min(12, 1 + (el.textContent || '').trim().length / 28);
          const textRole = ['A', 'BUTTON'].includes(el.tagName) ? 0.55 : (/^H[1-4]$/.test(el.tagName) ? 0.35 : 0.14);
          add(style.color, textWeight * textRole, 'text');
        }
        if (parseFloat(style.borderTopWidth) > 0) add(style.borderTopColor, semantic.has(el.tagName) ? 3 : 1, 'border');
        if (el.tagName === 'SVG') { add(style.fill, 2.5, 'icon'); add(style.stroke, 2, 'icon'); }
      }
      return result;
    });
    await page.addStyleTag({ content: `img,picture,video,canvas,iframe{visibility:hidden!important} *{background-image:none!important} *::before,*::after{background-image:none!important}` });
    await page.waitForTimeout(120);
    const uiOnly = await page.screenshot({ fullPage: true, type: 'jpeg', quality: 58 });
    const preview = await sharp(uiOnly).resize({ width: 768, withoutEnlargement: true }).jpeg({ quality: 62 }).toBuffer();
    return { image: preview, evidence: summarizeUiSamples(samples) };
  } finally {
    await page.close();
  }
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
    [/\b(yellow|gold|vàng)\b[\s\S]{0,180}\b(black|dark|đen)\b|\b(black|dark|đen)\b[\s\S]{0,180}\b(yellow|gold|vàng)\b/, 'Vàng đen'],
    [/\b(pink|hồng)\b[\s\S]{0,180}\b(black|dark|đen)\b|\b(black|dark|đen)\b[\s\S]{0,180}\b(pink|hồng)\b/, 'Hồng đen'],
    [/\b(blue|green|xanh)\b[\s\S]{0,180}\b(yellow|gold|vàng)\b|\b(yellow|gold|vàng)\b[\s\S]{0,180}\b(blue|green|xanh)\b/, 'Xanh vàng'],
    [/\b(pink|hồng)\b[\s\S]{0,180}\b(green|xanh)\b[\s\S]{0,180}\b(white|trắng)\b|\b(green|xanh)\b[\s\S]{0,180}\b(pink|hồng)\b[\s\S]{0,180}\b(white|trắng)\b/, 'Hồng xanh trắng'],
    [/\b(red|đỏ)\b[\s\S]{0,180}\b(white|trắng)\b|\b(white|trắng)\b[\s\S]{0,180}\b(red|đỏ)\b/, 'Đỏ trắng'],
    [/\b(pink|hồng)\b[\s\S]{0,180}\b(white|trắng)\b|\b(white|trắng)\b[\s\S]{0,180}\b(pink|hồng)\b/, 'Hồng trắng'],
    [/\b(red|đỏ)\b[\s\S]{0,180}\b(pink|hồng)\b|\b(pink|hồng)\b[\s\S]{0,180}\b(red|đỏ)\b/, 'Đỏ hồng'],
    [/\b(purple|tím)\b[\s\S]{0,180}\b(pink|hồng)\b|\b(pink|hồng)\b[\s\S]{0,180}\b(purple|tím)\b/, 'Tím hồng'],
    [/\b(brown|nâu)\b[\s\S]{0,180}\b(cream|kem)\b|\b(cream|kem)\b[\s\S]{0,180}\b(brown|nâu)\b/, 'Nâu kem'],
    [/\b(brown|nâu)\b[\s\S]{0,180}\b(white|trắng)\b|\b(white|trắng)\b[\s\S]{0,180}\b(brown|nâu)\b/, 'Nâu trắng'],
    [/\b(brown|nâu)\b[\s\S]{0,180}\b(yellow|gold|vàng)\b|\b(yellow|gold|vàng)\b[\s\S]{0,180}\b(brown|nâu)\b/, 'Nâu vàng'],
    [/\b(brown|nâu)\b[\s\S]{0,180}\b(black|dark|đen)\b|\b(black|dark|đen)\b[\s\S]{0,180}\b(brown|nâu)\b/, 'Nâu đen'],
    [/\b(purple|tím)\b[\s\S]{0,180}\b(white|trắng)\b|\b(white|trắng)\b[\s\S]{0,180}\b(purple|tím)\b/, 'Tím trắng'],
    [/\b(purple|tím)\b[\s\S]{0,180}\b(black|dark|đen)\b|\b(black|dark|đen)\b[\s\S]{0,180}\b(purple|tím)\b/, 'Tím đen'],
    [/\b(orange|cam)\b[\s\S]{0,180}\b(white|trắng)\b|\b(white|trắng)\b[\s\S]{0,180}\b(orange|cam)\b/, 'Cam trắng'],
    [/\b(orange|cam)\b[\s\S]{0,180}\b(black|dark|đen)\b|\b(black|dark|đen)\b[\s\S]{0,180}\b(orange|cam)\b/, 'Cam đen'],
  ].find(([pattern]) => pattern.test(normalized))?.[1];
  const candidateTone = tones.includes(parsed.tone) ? parsed.tone : (explicitTone || englishTone || '');
  // Strong measured UI evidence wins. For a borderline palette, let the
  // vision model inspect the UI-only image instead of forcing a weak rule.
  const tone = 'high' === evidence.certainty && evidence.inferred !== 'Cần duyệt' ? evidence.inferred : ( candidateTone || evidence.inferred );
  if (!tone) throw new Error(`Llama Vision returned no supported tone: ${text.replace(/\s+/g, ' ').slice(0, 500)}`);
  const confidenceMatch = text.match(/\b(high|medium|low)\b/i);
  return {
    tone,
    confidence: 'high' === evidence.certainty && evidence.inferred !== 'Cần duyệt' ? 'high' : (['high', 'medium', 'low'].includes(parsed.confidence) ? parsed.confidence : (confidenceMatch ? confidenceMatch[1].toLowerCase() : evidence.certainty)),
    reason: evidence.text.slice(0, 500),
  };
}

async function classify(snapshotId, uiImageBuffer, evidence, jobToken) {
  if (!cloudflareAccount || !cloudflareToken) return { skipped: true };
  const response = await fetch(`https://api.cloudflare.com/client/v4/accounts/${cloudflareAccount}/ai/run/@cf/meta/llama-3.2-11b-vision-instruct`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${cloudflareToken}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ prompt: tonePrompt(evidence), image: `data:image/jpeg;base64,${uiImageBuffer.toString('base64')}`, max_tokens: 160, temperature: 0.1 }),
  });
  if (response.status === 429) {
    await postJson({ mode: 'tone_deferred', snapshot_id: snapshotId, job_token: jobToken });
    return { skipped: true, quota: true };
  }
  if (!response.ok) throw new Error(`Llama Vision failed: HTTP ${response.status} ${await response.text()}`);
  const tone = parseTone(await response.json(), evidence);
  await postJson({ mode: 'tone', snapshot_id: snapshotId, job_token: jobToken, ...tone });
  return tone;
}

async function reportFailure(mode, item, error) {
  try {
    await postJson({ mode, snapshot_id: item.id, job_token: item.job_token, message: error.message });
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
      const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
      const file = join(workDir, `snapshot-${item.id}.jpg`);
      try {
        await page.goto(item.website_url, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const challenge = await page.evaluate(() => /performing security verification|verify you are human|checking your browser|just a moment\.\.\./i.test(`${document.title} ${document.body?.innerText || ''}`));
        if (challenge) throw new Error('Homepage is behind a Cloudflare security challenge; the real UI was not captured.');
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
        form.append('job_token', item.job_token);
        form.append('screenshot', new Blob([full], { type: 'image/jpeg' }), `mac-tracker-${item.id}.jpg`);
        await ingest(form);
        console.log(`Captured #${item.id}`);
      } catch (error) {
        await reportFailure('capture_failed', item, error);
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
  if (!items.length) return 0;
  const browser = await chromium.launch({ headless: true });
  try {
    for (const item of items) {
      try {
        const ui = await renderedUiEvidence(browser, item.website_url);
        const screenshotResponse = await fetch(item.screenshot_url);
        if (!screenshotResponse.ok) throw new Error(`Screenshot download failed: HTTP ${screenshotResponse.status}`);
        const screenshotEvidence = await screenshotColorEvidence(Buffer.from(await screenshotResponse.arrayBuffer()));
        const evidence = blendVisualEvidence(ui.evidence, screenshotEvidence);
        const result = await classify(item.id, ui.image, evidence, item.job_token);
        if (result.quota) { console.log('Workers AI daily quota reached; remaining tone jobs stay queued.'); break; }
        console.log(`Classified #${item.id}: ${result.tone} — ${evidence.text}`);
      } catch (error) {
        await reportFailure('tone_failed', item, error);
        console.warn(`Tone failed #${item.id}: ${error.message}`);
      }
    }
  } finally {
    await browser.close();
  }
  return items.length;
}

try {
  await captureBatch();
  await classifyPendingBatch();
} finally {
  await rm(workDir, { recursive: true, force: true });
}
