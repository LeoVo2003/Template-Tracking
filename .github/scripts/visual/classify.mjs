import { classifyWithGroq } from './providers/groq-qwen.mjs';
import { classifyWithGemini } from './providers/gemini.mjs';
import { classifyWithCloudflareQwen } from './providers/cloudflare-qwen.mjs';
import { ProviderError, TONES } from './providers/common.mjs';

export const tones = TONES;
export const AUTO_ACCEPT = 0.85;
export const REVIEW_BELOW = 0.75;
export const JUDGE_ACCEPT = 0.80;

function compactEvidence(evidence) {
  return {
    metrics_version: evidence.metrics_version || 0,
    candidate: evidence.candidate || 'Cần duyệt',
    candidate_confidence: evidence.candidate_confidence ?? null,
    primary_family: evidence.primary_family || 'neutral',
    secondary_family: evidence.secondary_family || 'neutral',
    surface: evidence.surface || 'mixed',
    coverage: evidence.coverage || {},
    average_saturation: evidence.average_saturation ?? null,
    average_luminance: evidence.average_luminance ?? null,
    warm_cool_tendency: evidence.warm_cool_tendency || 'balanced',
    contrast_level: evidence.contrast_level || 'low',
    dominant_structural_colors: (evidence.dominant_structural_colors || []).slice(0, 6),
  };
}

export function tonePrompt(evidence) {
  const deterministic = compactEvidence(evidence);
  return `Classify exactly one visual tone. The attached preview and deterministic UI metrics come from the same stored capture bundle. Judge structural UI only: repeated section backgrounds, header/footer bars, navigation, panels, buttons and large accents. Ignore photos, nail/skin/product/flower imagery, logo detail, body text, tiny icons, 1px borders and one-off decorative artifacts.

The deterministic metrics are 80% of this decision. The preview is a 20% tie-breaker only. Pale rose is pink. Beige/taupe/nude is brown or cream. Warm gold is yellow/gold, never red or pink. Red requires repeated, saturated true-red UI surfaces. Dark/black requires substantial dark UI area, never body text alone. If evidence is mixed or unclear, set tone to Cần duyệt and needs_review to true.

Area-weighted deterministic evidence: ${JSON.stringify(deterministic)}
Human-readable evidence: ${evidence.text || 'No reliable metric summary.'}

Allowed tones: ${tones.join(', ')}.
Return only the required JSON schema. Do not add markdown or extra fields.`;
}

function isStrongDeterministicCandidate(evidence) {
  return evidence?.candidate && 'Cần duyệt' !== evidence.candidate && Number(evidence.candidate_confidence || 0) >= REVIEW_BELOW;
}

function conflictsWithEvidence(result, evidence) {
  if (!isStrongDeterministicCandidate(evidence)) return false;
  if (result.tone !== evidence.candidate) return true;
  const coverage = evidence.coverage || {};
  const familyCoverage = {
    red: Number(coverage.red || 0), pink: Number(coverage.pink || 0), brown: Number(coverage.brown || 0), yellow: Number(coverage.yellow || 0),
    green: Number(coverage.green || 0), blue: Number(coverage.blue || 0), purple: Number(coverage.purple || 0),
  };
  if (['red', 'pink', 'brown', 'yellow', 'green', 'blue', 'purple'].includes(result.primary_family) && familyCoverage[result.primary_family] < 0.018) return true;
  return false;
}

function accepted(result, evidence) {
  return !result.needs_review && result.confidence >= AUTO_ACCEPT && !conflictsWithEvidence(result, evidence);
}

function serializableError(error) {
  return { provider: error.provider || 'unknown', code: error.code || 'PROVIDER_ERROR', message: String(error.message || 'Provider failed.').slice(0, 500), quota: Boolean(error.quota), retryable: Boolean(error.retryable) };
}

async function attempt(run, errors) {
  try { return await run(); } catch (error) { errors.push(serializableError(error)); return null; }
}

/**
 * Free-only provider chain. No paid provider/model is configured here. A quota
 * result can only advance to another configured free provider; it never falls
 * back to a paid SKU.
 */
export async function classifyTone({ previewBuffer, evidence, groqApiKey, geminiApiKey, cloudflareAccount, cloudflareToken, freeOnly = true, providers = {} }) {
  if (!freeOnly) throw new ProviderError('FREE_ONLY_REQUIRED', 'Visual-tone classification is locked to FREE_ONLY=true.');
  const prompt = tonePrompt(evidence);
  const errors = [];
  const options = { prompt, previewBuffer };
  const groq = providers.groq || classifyWithGroq;
  const cloudflare = providers.cloudflare || classifyWithCloudflareQwen;
  const geminiProvider = providers.gemini || classifyWithGemini;
  let qwen = await attempt(() => groq({ ...options, apiKey: groqApiKey }), errors);

  // Cloudflare is only an availability fallback for the same Qwen family.
  if (!qwen) qwen = await attempt(() => cloudflare({ ...options, accountId: cloudflareAccount, apiToken: cloudflareToken }), errors);
  if (qwen && accepted(qwen, evidence)) return { state: 'classified', result: qwen, attempts: [qwen], errors, free_only: Boolean(freeOnly) };

  // Gemini is an independent judge for a low-confidence or conflicting Qwen
  // result, and also the final free provider if Qwen is unavailable.
  const gemini = await attempt(() => geminiProvider({ ...options, apiKey: geminiApiKey }), errors);
  if (gemini && !gemini.needs_review && gemini.confidence >= JUDGE_ACCEPT && !conflictsWithEvidence(gemini, evidence)) return { state: 'classified', result: gemini, attempts: [qwen, gemini].filter(Boolean), errors, free_only: Boolean(freeOnly) };

  const attempts = [qwen, gemini].filter(Boolean);
  const allQuota = errors.length > 0 && errors.every((error) => error.quota);
  const allRetryable = errors.length > 0 && errors.every((error) => error.retryable);
  if (allQuota || allRetryable) return { state: 'retry_wait', result: null, attempts, errors, retry_code: allQuota ? 'FREE_QUOTA_EXHAUSTED' : 'FREE_PROVIDER_UNAVAILABLE', free_only: Boolean(freeOnly) };
  return {
    state: 'needs_review',
    result: gemini || qwen || { tone: 'Cần duyệt', confidence: 0, primary_family: 'neutral', secondary_family: 'neutral', surface: 'mixed', reason: 'Không có provider miễn phí nào trả kết quả hợp lệ.', needs_review: true, provider: '', model: '' },
    attempts,
    errors,
    free_only: Boolean(freeOnly),
  };
}

export { ProviderError };
