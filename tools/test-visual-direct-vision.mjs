import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { directVisionPrompt, validateDirectVisionResult, directVisionEligible } from '../.github/scripts/visual/direct-vision.mjs';
import { classifyTone } from '../.github/scripts/visual/classify.mjs';
import { summarizeUiSamples } from '../.github/scripts/visual/metrics.mjs';
import { brandEvidence, canvasFromFamilyCoverage } from '../.github/scripts/visual/color-engine.mjs';

const [main, classify, service, monitor, github, benchmark] = await Promise.all([
  readFile('.github/scripts/visual/main.mjs', 'utf8'),
  readFile('.github/scripts/visual/classify.mjs', 'utf8'),
  readFile('includes/class-mac-tracker-visual-service.php', 'utf8'),
  readFile('assets/visual-workflow-monitor.js', 'utf8'),
  readFile('includes/class-mac-tracker-github-actions.php', 'utf8'),
  readFile('tools/benchmark-visual-tone.mjs', 'utf8'),
]);

test('classifier defaults to legacy and direct mode uses full screenshot', () => {
  assert.match(main, /classifierMode = 'legacy'/);
  assert.match(main, /'direct_vision' === classifierMode \|\| 'benchmark_only' === classifierMode/);
  assert.match(classify, /classifierMode = 'legacy'/);
  assert.match(classify, /Direct Vision primary/);
  assert.match(service, /mac_tracker_visual_classifier_mode/);
});

test('canonical Direct Vision validator rejects free-form labels and accepts taxonomy', () => {
  const result = validateDirectVisionResult({ brand: 'gold', canvas: 'black', tone: 'Vàng đen', confidence: 0.94, reason: 'Repeated gold controls sit on dark structural sections.' }, 'fixture', 'fixture-model');
  assert.equal(result.tone, 'Vàng đen');
  assert.throws(() => validateDirectVisionResult({ brand: 'gold', canvas: 'black', tone: 'Gold black', confidence: 0.94, reason: 'free form' }), /canonical taxonomy/);
  assert.equal(directVisionEligible({ screenshot_url: 'https://cdn.test/full.jpg', diagnostic_screenshot_url: '', last_error_code: '' }), true);
  assert.equal(directVisionEligible({ screenshot_url: '', diagnostic_screenshot_url: 'https://cdn.test/403.jpg', last_error_code: 'HTTP_403' }), false);
});

test('direct mode invokes canonical validator and keeps Vision tone authoritative', async () => {
  const canonical = { brand: 'gold', canvas: 'black', tone: 'Vàng đen', confidence: 0.96, reason: 'Repeated gold controls sit on a dark structural canvas.' };
  let validatorCalls = 0;
  const directProvider = async ({ responseSchema, validateResult }) => {
    assert.equal(responseSchema.properties.tone.enum.includes('Vàng đen'), true);
    validatorCalls += 1;
    return validateResult(canonical, 'fixture', 'fixture-direct');
  };
  const outcome = await classifyTone({
    previewBuffer: Buffer.from('fixture'),
    evidence: { semantic_model: { brand: { brand_primary_score: 0.1 }, canvas: { primary_surface: 'white', family: 'white', mode: 'light' } } },
    freeOnly: true,
    classifierMode: 'direct_vision',
    providers: { groq: directProvider, cloudflare: directProvider, llama: directProvider, gemini: directProvider },
  });
  assert.equal(validatorCalls, 1);
  assert.equal(outcome.state, 'classified');
  assert.equal(outcome.authority, 'direct_vision');
  assert.equal(outcome.result.tone, 'Vàng đen');
  assert.equal(outcome.result.tone_group, 'Vàng đen');
});

test('prompt and benchmark are observational and compare legacy with Direct Vision', () => {
  assert.match(directVisionPrompt(), /complete rendered website screenshot/);
  assert.match(directVisionPrompt(), /Vàng đen/);
  assert.match(directVisionPrompt(), /Readable foreground text/);
  assert.match(benchmark, /direct_vision/);
  assert.match(benchmark, /direct_vision_tone_accuracy_percent/);
  assert.match(benchmark, /Direct Vision high-confidence wrong/);
  assert.doesNotMatch(benchmark, /save_visual_tone|wp-json\/mac-tracker/);
});

test('dark gold canvas ignores readable white text as structural evidence', () => {
  const samples = [
    { color: 'rgb(20, 20, 20)', weight: 0.70, kind: 'background', role: 'canvas', structural: true },
    { color: 'rgb(210, 160, 40)', weight: 0.20, kind: 'background', role: 'button', structural: true },
    { color: 'rgb(255, 255, 255)', weight: 0.10, kind: 'text', role: 'text' },
  ];
  const metrics = summarizeUiSamples(samples);
  assert.equal(metrics.surface, 'dark');
  assert.equal(metrics.legacy_candidate, 'Đen vàng');
  assert.equal(metrics.coverage.light, 0);
  assert.equal(metrics.foreground_text_contrast.role, 'contrast_only');
  assert.equal(metrics.foreground_text_contrast.coverage.light, 1);
  const evidence = brandEvidence(samples);
  assert.ok(evidence.find((row) => row.family === 'gold' && row.score > 0));
  assert.equal(evidence.find((row) => row.family === 'white')?.score || 0, 0);
  assert.equal(canvasFromFamilyCoverage({ black: 0.70, gold: 0.30 }).mode, 'dark');
});

test('light gold canvas keeps readable dark text out of brand evidence', () => {
  const samples = [
    { color: 'rgb(250, 248, 244)', weight: 0.70, kind: 'background', role: 'canvas', structural: true },
    { color: 'rgb(210, 160, 40)', weight: 0.20, kind: 'background', role: 'button', structural: true },
    { color: 'rgb(20, 20, 20)', weight: 0.10, kind: 'text', role: 'text' },
  ];
  const metrics = summarizeUiSamples(samples);
  assert.equal(metrics.surface, 'light');
  assert.equal(metrics.legacy_candidate, 'Vàng trắng');
  assert.equal(metrics.coverage.dark, 0);
  assert.equal(metrics.foreground_text_contrast.coverage.dark, 1);
  const evidence = brandEvidence(samples);
  assert.ok(evidence.find((row) => row.family === 'gold' && row.score > 0));
  assert.equal(evidence.find((row) => row.family === 'black')?.score || 0, 0);
  assert.equal(canvasFromFamilyCoverage({ white: 0.70, gold: 0.30 }).mode, 'light');
});

test('local workflow source and monitor include self-hosted executions', () => {
  assert.match(github, /LOCAL_WORKFLOW_FILE/);
  assert.match(github, /LOCAL_WORKFLOW_PATH/);
  assert.match(github, /dispatch_local/);
  assert.match(monitor, /local_retry/);
  assert.match(monitor, /self-hosted-windows/);
});
