import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';
import { dismissObstructiveOverlays } from '../.github/scripts/visual/capture.mjs';

const defaultUrls = [
  'https://magicnailsspalakewood.com/home',
  'https://tipsynails.macusa.us/home',
  'https://lushnailslashes.macusa.us/home',
  'https://sunlightnailsbrandon.com/home',
  'https://dnailscolima.com/home',
  'https://luxenailbarspa19121.com/home',
  'https://ginellenailspa.com/home',
  'https://azurenaillounge.com/home',
];

const urls = process.argv.slice(2).filter(Boolean);
let targets = urls.length ? urls : defaultUrls;
if ('1' === process.env.POPUP_QA_FROM_PROJECTS) {
  const projects = JSON.parse(await fs.readFile(path.resolve('data/all_projects.json'), 'utf8'));
  const projectUrls = projects.flatMap((project) => (project.tasks || [])
    .filter((task) => '[Website] Action Design' === task.title)
    .map((task) => String(task.extra_data?.web_demo_url || '').trim()))
    .filter((url) => /^https?:\/\//i.test(url));
  const offset = Math.max(0, Number.parseInt(process.env.POPUP_QA_OFFSET || '0', 10) || 0);
  const limit = Math.max(1, Math.min(100, Number.parseInt(process.env.POPUP_QA_LIMIT || '20', 10) || 20));
  targets = [...new Set(projectUrls)].slice(offset, offset + limit);
}
const scanOnly = '1' === process.env.POPUP_QA_SCAN_ONLY;
const outputDir = path.resolve(process.env.POPUP_QA_DIR || 'tmp/real-popup-qa');
await fs.mkdir(outputDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const report = [];
try {
  for (let index = 0; index < targets.length; index += 1) {
    const requestedUrl = targets[index];
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    const slug = `${String(index + 1).padStart(2, '0')}-${new URL(requestedUrl).hostname.replace(/[^a-z0-9.-]+/gi, '-')}`;
    try {
      const response = await page.goto(requestedUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => null);
      const pageHeight = await page.evaluate(() => Math.max(document.body?.scrollHeight || 0, document.documentElement?.scrollHeight || 0));
      for (let top = 0; top < pageHeight; top += 720) {
        await page.evaluate((value) => window.scrollTo(0, value), top);
        await page.waitForTimeout(90);
      }
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(2400);
      const beforePath = path.join(outputDir, `${slug}-before.png`);
      const afterPath = path.join(outputDir, `${slug}-after.png`);
      if (!scanOnly) await page.screenshot({ path: beforePath, fullPage: true });
      const cleanup = await dismissObstructiveOverlays(page);
      await page.waitForTimeout(250);
      if (!scanOnly) await page.screenshot({ path: afterPath, fullPage: true });
      report.push({
        requested_url: requestedUrl,
        final_url: page.url(),
        http_status: response?.status() || 0,
        page_title: await page.title(),
        cleanup,
        before: scanOnly ? null : beforePath,
        after: scanOnly ? null : afterPath,
      });
    } catch (error) {
      report.push({ requested_url: requestedUrl, final_url: page.url(), error: String(error?.message || error) });
    } finally {
      await context.close();
    }
  }
} finally {
  await browser.close();
}

const reportPath = path.join(outputDir, 'report.json');
await fs.writeFile(reportPath, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
console.log(JSON.stringify({ output_dir: outputDir, report: reportPath, targets: report.length, obstructive_detected: report.filter((row) => Number(row.cleanup?.detected || 0) > 0).length }, null, 2));
