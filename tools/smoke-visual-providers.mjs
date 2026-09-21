import { classifyWithGroq } from '../.github/scripts/visual/providers/groq-qwen.mjs';
import { classifyWithCloudflareQwen } from '../.github/scripts/visual/providers/cloudflare-qwen.mjs';
import { classifyWithLlamaScout } from '../.github/scripts/visual/providers/cloudflare-llama-scout.mjs';
import { classifyWithGemini } from '../.github/scripts/visual/providers/gemini.mjs';
import { DIRECT_VISION_SCHEMA, directVisionPrompt, validateDirectVisionResult } from '../.github/scripts/visual/direct-vision.mjs';
import { prepareVisionInput } from '../.github/scripts/visual/vision-input.mjs';

if ('false' === String(process.env.FREE_ONLY || 'true').toLowerCase()) throw new Error('Provider smoke is FREE_ONLY=true.');
const { default: sharp } = await import('sharp');
const fixture = await sharp({ create: { width: 160, height: 120, channels: 3, background: '#f7f1e7' } })
  .composite([{ input: Buffer.from('<svg width="160" height="120"><rect x="12" y="12" width="136" height="32" rx="8" fill="#c49a36"/><rect x="12" y="58" width="136" height="50" fill="#ffffff"/></svg>'), top: 0, left: 0 }])
  .jpeg({ quality: 80 }).toBuffer();
const prepared = await prepareVisionInput(fixture);
const previewBuffer = prepared.buffer;
const shared = { prompt: directVisionPrompt(), previewBuffer, responseSchema: DIRECT_VISION_SCHEMA, validateResult: validateDirectVisionResult };
const probes = [
  ['groq_qwen', () => classifyWithGroq({ ...shared, apiKey: process.env.GROQ_API_KEY || '' })],
  ['cloudflare_qwen', () => classifyWithCloudflareQwen({ ...shared, accountId: process.env.CLOUDFLARE_ACCOUNT_ID || '', apiToken: process.env.CLOUDFLARE_API_TOKEN || '' })],
  ['cloudflare_llama', () => classifyWithLlamaScout({ ...shared, accountId: process.env.CLOUDFLARE_ACCOUNT_ID || '', apiToken: process.env.CLOUDFLARE_API_TOKEN || '' })],
  ['gemini', () => classifyWithGemini({ ...shared, apiKey: process.env.GEMINI_API_KEY_1 || process.env.GEMINI_API_KEY || '' })],
];

const results = [];
for (const [provider, probe] of probes) {
  try {
    const result = await probe();
    results.push({ provider, status: 'success', model: result.model || '', tone: result.tone || '', confidence: result.confidence ?? null });
  } catch (error) {
    const code = String(error.code || 'PROVIDER_ERROR');
    const status = /UNCONFIGURED/.test(code) ? 'unconfigured' : (/QUOTA/.test(code) || error.quota ? 'quota' : (/UNAVAILABLE|NETWORK/.test(code) || error.retryable ? 'temporary_unavailable' : (/INVALID_SCHEMA/.test(code) ? 'unsupported_schema' : (/INVALID_RESPONSE|SEMANTIC_CONFLICT/.test(code) ? 'invalid_response' : 'failed'))));
    results.push({ provider, status, code, http_status: Number(error.status || 0), retryable: Boolean(error.retryable) });
  }
}
console.log(JSON.stringify({ free_only: true, fixture_bytes: previewBuffer.length, vision_input: prepared.metadata, results }, null, 2));
if (results.some((result) => !['success', 'quota', 'unconfigured', 'temporary_unavailable'].includes(result.status))) process.exitCode = 1;
