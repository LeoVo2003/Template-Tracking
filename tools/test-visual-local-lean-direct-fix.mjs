import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { classifyTone } from '../.github/scripts/visual/classify.mjs';
import { brandEvidence } from '../.github/scripts/visual/color-engine.mjs';
import { extractStructuredContent } from '../.github/scripts/visual/providers/common.mjs';

const [workflow, setup, main, service, admin, repository, monitor, benchmark, gold, cfQwen, cfLlama] = await Promise.all([
  readFile('.github/workflows/capture-visual-tone-local.yml', 'utf8'), readFile('scripts/setup-local-visual-runtime.ps1', 'utf8'),
  readFile('.github/scripts/visual/main.mjs', 'utf8'), readFile('includes/class-mac-tracker-visual-service.php', 'utf8'),
  readFile('includes/class-mac-tracker-admin.php', 'utf8'), readFile('includes/class-mac-tracker-repository.php', 'utf8'),
  readFile('assets/visual-workflow-monitor.js', 'utf8'), readFile('tools/benchmark-visual-tone.mjs', 'utf8'),
  readFile('data/visual-tone-gold.json', 'utf8'), readFile('.github/scripts/visual/providers/cloudflare-qwen.mjs', 'utf8'),
  readFile('.github/scripts/visual/providers/cloudflare-llama-scout.mjs', 'utf8'),
]);

test('local workflow consumes a persistent lean runtime and never installs per run', () => {
  assert.doesNotMatch(workflow, /actions\/setup-node|npm install|playwright install chromium/);
  assert.match(workflow, /MAC_VISUAL_RUNTIME_ROOT/);
  assert.match(workflow, /\.mac-visual-browsers/);
  assert.match(workflow, /github\.repository == 'LeoVo2003\/Template-Tracking'/);
  assert.match(workflow, /CLOUDFLARE_ACCOUNT_ID/);
  assert.match(setup, /--only-shell/);
  assert.match(setup, /Node\.js 20/);
  assert.doesNotMatch(setup, /Start-Process|svc\.cmd|config\.cmd/);
});

test('Direct Vision is visible and is the runtime/config default', () => {
  assert.match(main, /classifierMode = 'direct_vision'/);
  assert.match(service, /mac_tracker_visual_classifier_mode', 'direct_vision'/);
  assert.match(admin, /name="classifier_mode"/);
  assert.match(admin, />Direct Vision</);
  assert.match(admin, /complete rendered screenshot/);
  assert.match(main, /classifier_version: 'direct-vision-v1'/);
  assert.match(main, /vision_input/);
});

test('social colors have zero brand authority and neutral CTA-only evidence is gated', () => {
  const rows = brandEvidence([
    { color: 'rgb(24,119,242)', role: 'social', kind: 'background', area_ratio: 0.02, opacity: 1, section_key: 'footer', href_host: 'facebook.com' },
    { color: 'rgb(212,175,55)', role: 'heading', kind: 'background', area_ratio: 0.003, opacity: 1, section_key: 'hero' },
  ]);
  assert.equal(rows.find((row) => row.family === 'blue')?.score || 0, 0);
  assert.ok((rows.find((row) => row.family === 'gold')?.score || 0) > 0);
  assert.match(main, /directVisionEligible/);
  assert.match(repository, /diagnostic_screenshot_url = ''/);
});

test('strategy routing does not silently cross from Qwen-only into paid or Gemini providers', async () => {
  let geminiCalls = 0, llamaCalls = 0;
  const fail = async () => { const error = new Error('quota'); error.quota = true; error.retryable = true; error.code = 'QWEN_QUOTA'; throw error; };
  const outcome = await classifyTone({ previewBuffer: Buffer.from('x'), evidence: {}, freeOnly: true, classifierMode: 'direct_vision', aiStrategy: 'qwen', providers: { groq: fail, cloudflare: fail, llama: async () => { llamaCalls += 1; }, gemini: async () => { geminiCalls += 1; } } });
  assert.equal(outcome.state, 'retry_wait');
  assert.equal(llamaCalls, 0);
  assert.equal(geminiCalls, 0);
});

test('Workers AI uses json_object plus local validation and response parser accepts variants', () => {
  assert.match(cfQwen, /type: 'json_object'/);
  assert.match(cfLlama, /type: 'json_object'/);
  assert.doesNotMatch(cfQwen, /strict: true/);
  assert.deepEqual(extractStructuredContent({ result: { choices: [{ message: { parsed: { ok: true } } }] } }, 'fixture'), { ok: true });
  assert.deepEqual(extractStructuredContent({ choices: [{ message: { content: [{ type: 'text', text: '{"ok":true}' }] } }] }, 'fixture'), { ok: true });
  assert.deepEqual(extractStructuredContent({ choices: [{ message: { content: '```json\n{"ok":true}\n```' } }] }, 'fixture'), { ok: true });
  assert.deepEqual(extractStructuredContent({ choices: [{ message: { content: '<think>check {not json}</think> result: {"ok":true} done' } }] }, 'fixture'), { ok: true });
});

test('migration, diagnostics, benchmark policy and monitor polling follow the consolidated contract', () => {
  assert.match(repository, /requeue_legacy_ai_for_direct_vision/);
  assert.match(repository, /diagnostic_attachment_id' => 0/);
  assert.match(repository, /diagnostic_screenshot_url' => ''/);
  assert.doesNotMatch(monitor, /data\.runner_status/);
  assert.match(monitor, /Waiting for local capture machine/);
  assert.match(benchmark, /HTTP_401/);
  assert.match(benchmark, /prepareVisionInput/);
  const dataset = JSON.parse(gold);
  assert.equal(dataset.taxonomy_semantics, 'brand_first_canvas_second');
  assert.equal(dataset.entries.find((entry) => entry.id === 'magic').expected_tone, 'Vàng đen');
  assert.ok(dataset.entries.filter((entry) => entry.reviewed).length < dataset.minimum_reviewed_labels);
});
