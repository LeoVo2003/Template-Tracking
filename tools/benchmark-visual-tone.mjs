import { appendFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { classifyTone } from '../.github/scripts/visual/classify.mjs';
import { TONES } from '../.github/scripts/visual/providers/common.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const goldPath = resolve(root, 'data/visual-tone-gold.json');
const outputPath = resolve(root, process.env.BENCHMARK_OUTPUT || 'reports/visual-tone-benchmark.json');
const requestedLimit = Math.max(1, Math.min(50, Number(process.env.BENCHMARK_LIMIT || 50)));
const repeat = 'false' !== String(process.env.BENCHMARK_REPEAT || 'true').toLowerCase();
const minimum = 30;
const blockedCodes = new Set(['CF_CHALLENGE', 'CAPTCHA', 'PARKED_DOMAIN', 'MAINTENANCE', 'LOGIN_WALL', 'BAD_REDIRECT', 'EMPTY_PAGE']);

const gold = JSON.parse(await readFile(goldPath, 'utf8'));
const entries = Array.isArray(gold.entries) ? gold.entries : [];
const reviewed = entries.filter((entry) => entry.reviewed && TONES.includes(entry.expected_tone) && 'Cần duyệt' !== entry.expected_tone);
const missing = entries.filter((entry) => !entry.reviewed || !TONES.includes(entry.expected_tone) || 'Cần duyệt' === entry.expected_tone);
const baseReport = {
  generated_at: new Date().toISOString(),
  gold_set_version: gold.version || 0,
  total_candidates: entries.length,
  reviewed_labels: reviewed.length,
  required_labels: Math.max(minimum, Number(gold.minimum_reviewed_labels || 0)),
  pending_labels: missing.map((entry) => ({ id: entry.id, name: entry.name, url: entry.url })),
};

async function saveReport(report) {
  await mkdir(resolve(root, 'reports'), { recursive: true });
  await writeFile(outputPath, JSON.stringify(report, null, 2));
  const summary = [
    '## Visual Tone Benchmark', '',
    `- Reviewed gold labels: ${report.reviewed_labels}/${report.required_labels}`,
    `- Capture success: ${report.metrics?.capture_success_percent ?? 'n/a'}%`,
    `- False challenge/error classifications: ${report.metrics?.false_page_classifications ?? 'n/a'}`,
    `- Repeatability: ${report.metrics?.repeatability_percent ?? 'n/a'}%`,
    `- Tone-group accuracy: ${report.metrics?.tone_group_accuracy_percent ?? 'n/a'}%`,
    `- Direct Vision tone accuracy: ${report.metrics?.direct_vision_tone_accuracy_percent ?? 'n/a'}%`,
    `- Direct Vision high-confidence wrong: ${report.metrics?.direct_vision_high_confidence_wrong ?? 'n/a'}`,
    `- Precise-tone accuracy: ${report.metrics?.precise_tone_accuracy_percent ?? 'n/a'}%`,
    `- Family / canvas-mode accuracy: ${report.metrics?.family_accuracy_percent ?? 'n/a'}% / ${report.metrics?.canvas_mode_accuracy_percent ?? 'n/a'}%`,
    `- AUTO eligible: ${report.pass ? 'YES' : 'NO'}`, '',
  ].join('\n');
  console.log(summary);
  if (process.env.GITHUB_STEP_SUMMARY) await appendFile(process.env.GITHUB_STEP_SUMMARY, `${summary}\n`);
}

if (reviewed.length < baseReport.required_labels) {
  await saveReport({ ...baseReport, pass: false, blocked: 'INSUFFICIENT_MANUAL_GOLD_LABELS', message: 'Benchmark deliberately did not run: review at least 30 gold labels first.' });
  process.exitCode = 2;
} else {
	const { chromium } = await import('playwright');
	const { captureRenderedPage } = await import('../.github/scripts/visual/capture.mjs');
  const selected = reviewed.slice(0, requestedLimit);
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [index, entry] of selected.entries()) {
      const row = { id: entry.id, name: entry.name, url: entry.url, expected_tone: entry.expected_tone, expected_precise_tone: entry.expected_precise_tone || null, expected_primary_family: entry.expected_primary_family || null, expected_canvas_mode: entry.expected_canvas_mode || null, expected_primary_surface: entry.expected_primary_surface || null };
      try {
        const first = await captureRenderedPage(browser, entry.url, index + 1, `benchmark-${Date.now()}-${index}`);
        row.capture = 'ok';
        row.final_url = first.bundle.final_url;
        const primary = await classifyTone({
          previewBuffer: first.preview,
          evidence: first.bundle.ui.metrics,
          groqApiKey: process.env.GROQ_API_KEY || '', geminiApiKey: process.env.GEMINI_API_KEY || '',
          cloudflareAccount: process.env.CLOUDFLARE_ACCOUNT_ID || '', cloudflareToken: process.env.CLOUDFLARE_API_TOKEN || '',
          geminiApiKeys: [process.env.GEMINI_API_KEY_1 || process.env.GEMINI_API_KEY || '', process.env.GEMINI_API_KEY_2 || ''], freeOnly: true, autoAccept: Number(process.env.BENCHMARK_AUTO_ACCEPT || 0.85),
        });
        row.first = { state: primary.state, tone: primary.result?.tone_group || primary.result?.tone || null, precise_tone: primary.result?.precise_tone || null, primary_family: primary.result?.primary_family || null, canvas_mode: primary.result?.canvas_mode || null, primary_surface: primary.result?.primary_surface || null, provider: primary.result?.provider || null, confidence: primary.result?.confidence ?? null };
        const direct = await classifyTone({
          previewBuffer: first.full,
          evidence: first.bundle.ui.metrics,
          groqApiKey: process.env.GROQ_API_KEY || '', geminiApiKey: process.env.GEMINI_API_KEY || '',
          cloudflareAccount: process.env.CLOUDFLARE_ACCOUNT_ID || '', cloudflareToken: process.env.CLOUDFLARE_API_TOKEN || '',
          geminiApiKeys: [process.env.GEMINI_API_KEY_1 || process.env.GEMINI_API_KEY || '', process.env.GEMINI_API_KEY_2 || ''], freeOnly: true, autoAccept: Number(process.env.BENCHMARK_AUTO_ACCEPT || 0.85), classifierMode: 'direct_vision',
        });
        row.direct_vision = { state: direct.state, tone: direct.result?.tone_group || direct.result?.tone || null, precise_tone: direct.result?.precise_tone || null, primary_family: direct.result?.primary_family || null, canvas_mode: direct.result?.canvas_mode || null, primary_surface: direct.result?.primary_surface || null, provider: direct.result?.provider || null, confidence: direct.result?.confidence ?? null, authority: direct.authority || 'judge_or_review' };
        if (repeat && 'classified' === primary.state) {
          const second = await captureRenderedPage(browser, entry.url, index + 1, `benchmark-repeat-${Date.now()}-${index}`);
          const repeated = await classifyTone({
            previewBuffer: second.preview, evidence: second.bundle.ui.metrics,
            groqApiKey: process.env.GROQ_API_KEY || '', geminiApiKey: process.env.GEMINI_API_KEY || '',
            cloudflareAccount: process.env.CLOUDFLARE_ACCOUNT_ID || '', cloudflareToken: process.env.CLOUDFLARE_API_TOKEN || '',
            geminiApiKeys: [process.env.GEMINI_API_KEY_1 || process.env.GEMINI_API_KEY || '', process.env.GEMINI_API_KEY_2 || ''], freeOnly: true, autoAccept: Number(process.env.BENCHMARK_AUTO_ACCEPT || 0.85),
          });
          row.repeat = { state: repeated.state, tone: repeated.result?.tone_group || repeated.result?.tone || null, precise_tone: repeated.result?.precise_tone || null };
        }
      } catch (error) {
        row.capture = blockedCodes.has(error.code) ? 'blocked' : 'failed';
        row.error_code = error.code || 'BENCHMARK_ERROR';
        row.error = String(error.message || error).slice(0, 500);
      }
      results.push(row);
    }
  } finally {
    await browser.close();
  }
  const eligible = results.filter((row) => 'blocked' !== row.capture);
  const captured = eligible.filter((row) => 'ok' === row.capture);
  const classified = captured.filter((row) => 'classified' === row.first?.state);
  const repeatable = classified.filter((row) => row.repeat && 'classified' === row.repeat.state);
  const correct = classified.filter((row) => row.first.tone === row.expected_tone);
  const directClassified = captured.filter((row) => 'classified' === row.direct_vision?.state);
  const directCorrect = directClassified.filter((row) => row.direct_vision.tone === row.expected_tone);
  const preciseLabeled = classified.filter((row) => row.expected_precise_tone);
  const familyLabeled = classified.filter((row) => row.expected_primary_family);
  const canvasModeLabeled = classified.filter((row) => row.expected_canvas_mode);
  const surfaceLabeled = classified.filter((row) => row.expected_primary_surface);
  const falsePages = results.filter((row) => 'blocked' === row.capture && row.first?.tone).length;
  const percent = (part, total) => total ? Number((part * 100 / total).toFixed(1)) : 0;
  const metrics = {
    capture_success_percent: percent(captured.length, eligible.length),
    false_page_classifications: falsePages,
    repeatability_percent: repeat ? percent(repeatable.filter((row) => row.first.tone === row.repeat.tone).length, repeatable.length) : null,
    tone_group_accuracy_percent: percent(correct.length, classified.length),
    direct_vision_tone_accuracy_percent: percent(directCorrect.length, directClassified.length),
    direct_vision_high_confidence_wrong: directClassified.filter((row) => Number(row.direct_vision.confidence || 0) >= 0.90 && row.direct_vision.tone !== row.expected_tone).length,
    direct_vision_needs_review: captured.filter((row) => 'classified' !== row.direct_vision?.state).length,
    precise_tone_accuracy_percent: preciseLabeled.length ? percent(preciseLabeled.filter((row) => row.first.precise_tone === row.expected_precise_tone).length, preciseLabeled.length) : null,
    family_accuracy_percent: familyLabeled.length ? percent(familyLabeled.filter((row) => row.first.primary_family === row.expected_primary_family).length, familyLabeled.length) : null,
    canvas_mode_accuracy_percent: canvasModeLabeled.length ? percent(canvasModeLabeled.filter((row) => row.first.canvas_mode === row.expected_canvas_mode).length, canvasModeLabeled.length) : null,
    canvas_surface_accuracy_percent: surfaceLabeled.length ? percent(surfaceLabeled.filter((row) => row.first.primary_surface === row.expected_primary_surface).length, surfaceLabeled.length) : null,
    provider_usage: classified.reduce((counts, row) => { const provider = row.first?.provider || 'none'; counts[provider] = (counts[provider] || 0) + 1; return counts; }, {}),
    confusion_matrix: classified.filter((row) => row.first.tone !== row.expected_tone).reduce((counts, row) => { const key = `${row.expected_tone}->${row.first.tone}`; counts[key] = (counts[key] || 0) + 1; return counts; }, {}),
    confusion_counters: familyLabeled.reduce((counts, row) => { const key = `${row.expected_primary_family}_to_${row.first.primary_family}`; if (row.expected_primary_family !== row.first.primary_family) counts[key] = (counts[key] || 0) + 1; return counts; }, { navy_to_black: 0, gray_to_white: 0, greige_to_cream: 0, beige_to_brown: 0, pink_to_cream: 0, gold_to_brown: 0, orange_to_brown: 0 }),
  };
  const pass = metrics.capture_success_percent >= 95 && 0 === metrics.false_page_classifications && (!repeat || metrics.repeatability_percent >= 95) && metrics.tone_group_accuracy_percent >= 90 && metrics.direct_vision_tone_accuracy_percent >= 90 && metrics.direct_vision_high_confidence_wrong === 0;
  await saveReport({ ...baseReport, selected: selected.length, repeat, metrics, pass, results });
  if (!pass) process.exitCode = 1;
}
