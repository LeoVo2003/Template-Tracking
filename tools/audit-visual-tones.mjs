import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// Run with a Playwright module available (the GitHub workflow already installs
// it). This deliberately reads DOM UI styles only: photos are excluded.
const { chromium } = await import(process.env.MAC_TRACKER_PLAYWRIGHT_MODULE || 'playwright');
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const projects = JSON.parse(await readFile(resolve(root, 'data/all_projects.json'), 'utf8'));
const sampleSize = Math.max(1, Math.min(300, Number(process.env.SAMPLE_SIZE || 300)));
const workers = Math.max(1, Math.min(8, Number(process.env.SAMPLE_WORKERS || 5)));

const cleanUrl = (value) => (String(value || '').match(/https?:\/\/[^\s<>()]+/i) || [])[0] || '';
const sourceTasks = projects.flatMap((project) => (project.tasks || [])
  .filter((task) => task.title === '[Website] Action Design')
  .map((task) => ({
    url: cleanUrl(task.extra_data?.web_demo_url),
    taskId: task.id,
    projectId: project.id,
    projectName: project.full_name || project.name || '',
  })))
  .filter((task) => /^https?:\/\//i.test(task.url) && !/sharepoint|docs\.google|mailserver/i.test(task.url));
const byUrl = new Map(sourceTasks.map((task) => [task.url, task]));
const sources = [...byUrl.values()];
for (let index = sources.length - 1; index > 0; index -= 1) {
  const pick = Math.floor(Math.random() * (index + 1));
  [sources[index], sources[pick]] = [sources[pick], sources[index]];
}
const selected = sources.slice(0, sampleSize);

function hsl({ r, g, b }) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b), delta = max - min;
  let hue = 0;
  if (delta) hue = max === r ? 60 * (((g - b) / delta + 6) % 6) : (max === g ? 60 * ((b - r) / delta + 2) : 60 * ((r - g) / delta + 4));
  const light = (max + min) / 2;
  return { hue, saturation: delta ? delta / (1 - Math.abs(2 * light - 1)) : 0, light };
}

function category(rgb) {
  const { hue, saturation, light } = hsl(rgb);
  if (light <= 0.22) return 'dark';
  if (saturation <= 0.12) return light >= 0.72 ? 'light' : (light <= 0.38 ? 'dark' : 'neutral');
  if (light >= 0.82 && saturation <= 0.38 && (hue < 70 || hue >= 330)) return 'cream';
  if ((hue < 8 || hue >= 350) && light < 0.58 && saturation >= 0.5 && rgb.r > rgb.g * 1.25 && rgb.r > rgb.b * 1.25) return 'red';
  if ((hue >= 315 || hue < 14) && light >= 0.56) return 'pink';
  if (hue >= 315 && hue < 350) return 'pink';
  if (hue >= 255 && hue < 315) return 'purple';
  if (hue >= 165 && hue < 255) return 'blue';
  if (hue >= 72 && hue < 165) return 'green';
  if (hue >= 38 && hue < 72) return 'yellow';
  if (hue >= 14 && hue < 38) return light < 0.76 ? 'brown' : 'cream';
  return light >= 0.72 ? 'light' : 'neutral';
}

function classify(buckets) {
  const chromatic = ['red', 'pink', 'brown', 'yellow', 'green', 'blue', 'purple'];
  const total = buckets.total || 1;
  const coverage = Object.fromEntries(chromatic.map((key) => [key, buckets[key] / total]));
  const chroma = chromatic.reduce((sum, key) => sum + coverage[key], 0);
  const mix = Object.fromEntries(chromatic.map((key) => [key, chroma ? coverage[key] / chroma : 0]));
  const dark = buckets.dark / total, light = (buckets.light + buckets.cream) / total, cream = buckets.cream / total;
  const greenBlue = coverage.green + coverage.blue;
  let tone = 'Cần duyệt';
  if (chroma < 0.045) tone = dark >= 0.56 ? 'Đen trắng' : (cream >= 0.23 ? 'Trắng kem' : tone);
  else if (dark >= 0.50 && coverage.yellow >= 0.035) tone = 'Đen vàng';
  else if (dark >= 0.50 && greenBlue >= 0.045) tone = 'Xanh đen';
  else if (coverage.yellow >= 0.06 && dark >= 0.38) tone = 'Vàng đen';
  else if (coverage.pink >= 0.06 && dark >= 0.32) tone = 'Hồng đen';
  else if (coverage.pink >= 0.045 && coverage.green >= 0.028) tone = 'Hồng xanh trắng';
  else if (coverage.brown >= 0.065 && mix.brown >= 0.30) tone = dark >= 0.34 ? 'Nâu đen' : (coverage.yellow >= 0.035 ? 'Nâu vàng' : (light >= 0.44 ? 'Nâu trắng' : 'Nâu kem'));
  else if (coverage.red >= 0.05 && mix.red >= 0.38) tone = dark >= 0.32 ? 'Đỏ đen' : 'Đỏ trắng';
  else if (coverage.pink >= 0.04 && mix.pink >= 0.30) tone = 'Hồng trắng';
  else if (coverage.red >= 0.025 && coverage.pink >= 0.03) tone = 'Đỏ hồng';
  else if (coverage.purple >= 0.045 && dark >= 0.34) tone = 'Tím đen';
  else if (coverage.purple >= 0.045 && light >= 0.45) tone = 'Tím trắng';
  else if (coverage.purple >= 0.035 && coverage.pink >= 0.025) tone = 'Tím hồng';
  else if (greenBlue >= 0.05 && coverage.yellow >= 0.025) tone = 'Xanh vàng';
  else if (greenBlue >= 0.06) tone = cream >= 0.20 ? 'Xanh kem' : (light >= 0.34 ? 'Xanh trắng' : 'Xanh đen');
  else if (coverage.yellow >= 0.055) tone = light >= 0.42 ? 'Vàng trắng' : 'Vàng kem sáng';
  else if (cream >= 0.28) tone = 'Trắng kem';
  return { tone, coverage: Object.fromEntries(Object.entries(coverage).map(([key, value]) => [key, Math.round(value * 100)])) };
}

async function inspect(browser, source) {
  const { url } = source;
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 16000 });
    await page.waitForTimeout(800);
    const samples = await page.evaluate(() => {
      const pageText = `${document.title} ${document.body?.innerText || ''}`;
      if (/performing security verification|verify you are human|checking your browser|just a moment\.\.\./i.test(pageText)) return { challenge: true };
      const result = [];
      const add = (color, weight) => { const match = String(color).match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?/); if (match && Number(match[4] ?? 1) > 0.03 && weight > 0) result.push([+match[1], +match[2], +match[3], weight]); };
      const semantic = new Set(['HEADER', 'NAV', 'MAIN', 'SECTION', 'ARTICLE', 'FOOTER', 'BUTTON']);
      const nodes = [document.body, ...document.querySelectorAll('header,nav,main,section,article,footer,button,a,h1,h2,h3,h4,p,div,li,input,select,textarea,svg')];
      for (const element of nodes.slice(0, 5000)) {
        if (element.matches?.('img,picture,video,canvas,iframe,source') || element.closest?.('picture,video')) continue;
        const rect = element.getBoundingClientRect(), style = getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity || 1) <= 0.03 || rect.width < 2 || rect.height < 2) continue;
        const structural = semantic.has(element.tagName) ? 1.9 : 1, area = Math.min(rect.width * rect.height, innerWidth * 1400);
        const parentBackground = element.parentElement ? getComputedStyle(element.parentElement).backgroundColor : '';
        if (style.backgroundColor !== parentBackground || semantic.has(element.tagName)) add(style.backgroundColor, Math.min(55, Math.sqrt(area) / 20) * structural);
        const text = [...element.childNodes].some((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
        if (text || /^H[1-4]$/.test(element.tagName) || ['A', 'BUTTON'].includes(element.tagName)) {
          const textWeight = Math.min(12, 1 + (element.textContent || '').trim().length / 28);
          const textRole = ['A', 'BUTTON'].includes(element.tagName) ? 0.55 : (/^H[1-4]$/.test(element.tagName) ? 0.35 : 0.14);
          add(style.color, textWeight * textRole);
        }
        if (parseFloat(style.borderTopWidth) > 0) add(style.borderTopColor, semantic.has(element.tagName) ? 3 : 1);
      }
      return { samples: result };
    });
    if (samples.challenge) return { ...source, status: 'challenge' };
    const buckets = { red: 0, pink: 0, brown: 0, yellow: 0, green: 0, blue: 0, purple: 0, dark: 0, light: 0, cream: 0, neutral: 0, total: 0 };
    for (const [r, g, b, weight] of samples.samples) { buckets[category({ r, g, b })] += weight; buckets.total += weight; }
    return { ...source, status: 'ok', ...classify(buckets) };
  } catch (error) {
    return { ...source, status: 'failed', reason: error.message.replace(/\s+/g, ' ').slice(0, 180) };
  } finally { await page.close(); }
}

const browser = await chromium.launch({ headless: true, executablePath: process.env.MAC_TRACKER_CHROME_PATH || undefined });
let next = 0;
const results = [];
await Promise.all(Array.from({ length: workers }, async () => { while (next < selected.length) results.push(await inspect(browser, selected[next++])); }));
await browser.close();
const completed = results.filter((result) => 'ok' === result.status);
const report = { source: 'task.extra_data.web_demo_url from [Website] Action Design', sourceTasks: sourceTasks.length, uniqueUrls: sources.length, selected: selected.length, analyzed: completed.length, challenged: results.filter((result) => 'challenge' === result.status).length, failed: results.filter((result) => 'failed' === result.status).length, tones: completed.reduce((count, result) => ({ ...count, [result.tone]: (count[result.tone] || 0) + 1 }), {}), results };
if (process.env.SAMPLE_OUTPUT) await writeFile(process.env.SAMPLE_OUTPUT, JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
