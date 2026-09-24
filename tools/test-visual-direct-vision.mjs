import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { directVisionJudgePrompt, directVisionPrompt, validateDirectVisionResult, directVisionEligible } from '../.github/scripts/visual/direct-vision.mjs';
import { brandCompatibility, classifyTone, evaluateDirectVisionConflict } from '../.github/scripts/visual/classify.mjs';
import { summarizeUiSamples } from '../.github/scripts/visual/metrics.mjs';
import { brandEvidence, canvasFromFamilyCoverage } from '../.github/scripts/visual/color-engine.mjs';

const [main, classify, service, admin, monitor, github, benchmark] = await Promise.all([
  readFile('.github/scripts/visual/main.mjs', 'utf8'),
  readFile('.github/scripts/visual/classify.mjs', 'utf8'),
  readFile('includes/class-mac-tracker-visual-service.php', 'utf8'),
  readFile('includes/class-mac-tracker-admin.php', 'utf8'),
  readFile('assets/visual-workflow-monitor.js', 'utf8'),
  readFile('includes/class-mac-tracker-github-actions.php', 'utf8'),
  readFile('tools/benchmark-visual-tone.mjs', 'utf8'),
]);

test('classifier defaults to Direct Vision and uses full screenshot', () => {
  assert.match(main, /classifierMode = 'direct_vision'/);
  assert.match(main, /'direct_vision' === classifierMode \|\| 'benchmark_only' === classifierMode/);
  assert.match(classify, /classifierMode = 'direct_vision'/);
  assert.match(classify, /Direct Vision primary/);
  assert.match(service, /mac_tracker_visual_classifier_mode/);
});

test('canonical Direct Vision validator rejects free-form labels and accepts taxonomy', () => {
  const result = validateDirectVisionResult({ brand: 'gold', canvas: 'black', tone: 'Vàng đen', confidence: 0.94, reason: 'Repeated gold controls sit on dark structural sections.' }, 'fixture', 'fixture-model');
  assert.equal(result.tone, 'Vàng đen');
  assert.throws(() => validateDirectVisionResult({ brand: 'gold', canvas: 'black', tone: 'Gold black', confidence: 0.94, reason: 'free form' }), /canonical taxonomy/);
  assert.throws(() => validateDirectVisionResult({ brand: 'gold', canvas: 'black', tone: 'Đen vàng', confidence: 0.94, reason: 'reversed semantics' }), /maps to Vàng đen/);
  assert.equal(directVisionEligible({ screenshot_url: 'https://cdn.test/full.jpg', diagnostic_screenshot_url: '', last_error_code: '' }), true);
  assert.equal(directVisionEligible({ screenshot_url: '', diagnostic_screenshot_url: 'https://cdn.test/403.jpg', last_error_code: 'HTTP_403' }), false);
  assert.equal(validateDirectVisionResult({ brand: 'green', canvas: 'gold', tone: 'Xanh vàng', confidence: 0.91, reason: 'Leaf-green controls repeat across gold structural panels.' }, 'fixture', 'fixture-model').tone, 'Xanh vàng');
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
  assert.equal(outcome.result.precise_tone, '');
});

function directEvidence({ canvas = 'ivory', mode = 'light', canvasConfidence = 0.97, brand = 'champagne', score = 0.1, sections = 1, roles = ['cta'], hex = '#D8C08B' } = {}) {
  return { semantic_model: {
    canvas: { family: canvas, primary_surface: canvas, secondary_surface: '', mode, surface_confidence: canvasConfidence, light_surface_ratio: 'light' === mode ? 0.9 : 0.05, dark_surface_ratio: 'dark' === mode ? 0.9 : 0.05 },
    brand: { brand_primary_family: brand, brand_primary_score: score, brand_confidence: 0.8, brand_evidence: [{ family: brand, score, section_count: sections, control_count: 2, roles, hex, sources: Array.from({ length: sections }, (_, index) => ({ role: roles[0], section_key: `section-${index + 1}` })) }] },
    primary_accent: { family: brand, score, hex },
  } };
}

test('hard canvas conflict cannot auto-pass and an unchanged judge goes to review', async () => {
  const primary = { brand: 'gold', canvas: 'black', tone: 'Vàng đen', confidence: 0.94, reason: 'Gold logo and controls on an apparently dark page.', provider: 'fixture_primary', model: 'fixture' };
  let judgeCalls = 0;
  const outcome = await classifyTone({
    previewBuffer: Buffer.from('fixture'), evidence: directEvidence(), freeOnly: true, classifierMode: 'direct_vision', aiStrategy: 'smart',
    providers: { groq: async () => primary, llama: async () => { judgeCalls += 1; return { ...primary, confidence: 0.93, provider: 'fixture_judge' }; } },
  });
  assert.equal(judgeCalls, 1);
  assert.equal(outcome.state, 'needs_review');
  assert.equal(outcome.conflict.severity, 'hard');
  assert.equal(outcome.conflict.canvas_conflict, true);
  assert.match(outcome.conflict.reasons[0], /black.*ivory\/light.*0\.97/i);
});

test('nearby warm brand families stay compatible and preserve a coherent primary result', async () => {
  const primary = { brand: 'gold', canvas: 'cream', tone: 'Vàng kem', confidence: 0.92, reason: 'Repeated mustard-gold UI accents on warm cream surfaces.', provider: 'fixture_primary', model: 'fixture' };
  const evidence = directEvidence({ canvas: 'ivory', brand: 'brown', score: 0.35, sections: 2, roles: ['cta'] });
  const outcome = await classifyTone({ previewBuffer: Buffer.from('fixture'), evidence, freeOnly: true, classifierMode: 'direct_vision', providers: { groq: async () => primary } });
  assert.equal(brandCompatibility('gold', 'brown'), 'nearby');
  assert.equal(outcome.state, 'classified');
  assert.equal(outcome.authority, 'direct_vision');
  assert.equal(outcome.result.tone, 'Vàng kem');
  assert.equal(outcome.conflict.brand_conflict, false);
});

test('repeated burgundy UI evidence forces a real judge and logo gold cannot remain primary by confidence alone', async () => {
  const primary = { brand: 'gold', canvas: 'white', tone: 'Vàng trắng', confidence: 0.94, reason: 'Gold brand mark on a white page.', provider: 'fixture_primary', model: 'fixture-primary' };
  const judged = { brand: 'pink', canvas: 'white', tone: 'Hồng trắng', confidence: 0.91, reason: 'Pink sections and burgundy controls repeat throughout the interface.', provider: 'fixture_judge', model: 'fixture-judge' };
  const evidence = directEvidence({ canvas: 'ivory', brand: 'terracotta', score: 0.2349, sections: 2, roles: ['cta'], hex: '#510E15' });
  let receivedPrompt = '';
  const outcome = await classifyTone({
    previewBuffer: Buffer.from('fixture'), evidence, freeOnly: true, classifierMode: 'direct_vision', providers: { groq: async () => primary, llama: async ({ prompt }) => { receivedPrompt = prompt; return judged; } },
  });
  assert.equal(outcome.state, 'classified');
  assert.equal(outcome.authority, 'direct_vision_judge');
  assert.equal(outcome.result.tone, 'Hồng trắng');
  assert.equal(outcome.conflict.brand_conflict, true);
  assert.equal(outcome.conflict.resolved, true);
  assert.match(receivedPrompt, /Previous Vision result/);
  assert.match(receivedPrompt, /"brand":"gold"/);
  assert.match(receivedPrompt, /Deterministic canvas evidence/);
  assert.match(receivedPrompt, /Deterministic brand evidence/);
  assert.match(receivedPrompt, /terracotta/);
  assert.match(receivedPrompt, /Exact conflict reasons/);
});

test('judge prompt always carries previous result, deterministic evidence and exact conflict reasons', () => {
  const prompt = directVisionJudgePrompt({ previousResult: { brand: 'gold', canvas: 'black', tone: 'Vàng đen', confidence: 0.94 }, deterministic: { canvas: { family: 'ivory', mode: 'light', confidence: 0.97 }, brand: { family: 'champagne', score: 0.1011 } }, conflict: { severity: 'hard', reasons: ['Vision black conflicts with ivory/light.'] } });
  assert.match(prompt, /Previous Vision result/);
  assert.match(prompt, /"canvas":"black"/);
  assert.match(prompt, /"family":"ivory"/);
  assert.match(prompt, /"family":"champagne"/);
  assert.match(prompt, /Vision black conflicts with ivory\/light/);
});

test('provider debug reads Direct Vision fields and exposes conflict details', () => {
  assert.match(admin, /\$attempt\['brand'\] \?\? \$attempt\['primary_family'\]/);
  assert.match(admin, /\$attempt\['canvas'\] \?\? \$attempt\['primary_surface'\]/);
  assert.match(admin, /\$attempt\['tone'\] \?\? \$attempt\['tone_group'\]/);
  assert.match(admin, /Sanity conflict/);
  assert.match(admin, /Conflict reason/);
  assert.match(main, /direct-vision-v2-conflict-judge/);
});

test('prompt and benchmark are observational and compare legacy with Direct Vision', () => {
  assert.match(directVisionPrompt(), /complete rendered website screenshot/);
  assert.match(directVisionPrompt(), /Vàng đen/);
  assert.match(directVisionPrompt(), /Readable foreground text/);
  assert.match(directVisionPrompt(), /Social-network icons/);
  assert.match(directVisionPrompt(), /logo or wordmark color is supporting evidence only/i);
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
