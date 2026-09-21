import { classifyWithGroq } from './providers/groq-qwen.mjs';
import { classifyWithCloudflareQwen } from './providers/cloudflare-qwen.mjs';
import { classifyWithLlamaScout } from './providers/cloudflare-llama-scout.mjs';
import { classifyWithGemini } from './providers/gemini.mjs';
import { ProviderError, FAMILIES, CANVAS_FAMILIES } from './providers/common.mjs';
import { resolveTone } from './tone-map.mjs';
import { DIRECT_VISION_SCHEMA, directVisionPrompt, validateDirectVisionResult } from './direct-vision.mjs';

export const AUTO_ACCEPT = 0.85;
const JUDGE_ACCEPT = 0.8;
const GEMINI_DAILY_BUDGET = Math.max(1, Number.parseInt(process.env.GEMINI_DAILY_BUDGET_PER_KEY || '8', 10) || 8);
const geminiPool = new Map();
const schemaPrompt = `Return JSON only: canvas_mode(light|dark|mixed), canvas_family, primary_surface, secondary_surface (${CANVAS_FAMILIES.join('|')}), primary_family and secondary_family (${FAMILIES.join('|')}), style_tone(light_minimal|corporate_clean|dark_modern|vibrant_bold|warm_earthy|soft_pastel), confidence(0..1), reason, needs_review. Do not output a Vietnamese label.`;

function evidenceForAi(evidence) {
  const semantic = evidence.semantic_model || {};
  return { canvas: semantic.canvas, brand: semantic.brand, palette: semantic.palette, families: semantic.families, primary_accent: semantic.primary_accent, secondary_accent: semantic.secondary_accent, role_evidence: semantic.role_evidence, structural_evidence: semantic.structural_evidence, ambiguous: semantic.ambiguous };
}
export function tonePrompt(evidence) {
  return `You are a Lead UI/UX Color System Auditor. Analyze the WEBSITE DESIGN SYSTEM, not content imagery. Ignore photos, skin, nails, products, flowers, model clothing and illustrations. CANVAS means large structural surfaces. BRAND PRIMARY means the repeated intentional UI accent in CTA, active navigation, buttons, headings, borders, controls and decorative UI. A large black/white/cream background is NOT automatically the brand primary; a smaller repeatedly-used gold/pink/blue accent may be the brand. Use the supplied role recurrence evidence over raw pixel area. Carefully distinguish pale pink/cream, peach/orange/pink, beige/taupe/brown, gold/yellow, navy/black, teal/blue/green. If ambiguous set needs_review=true. Do not output a Vietnamese final label. ${schemaPrompt}\nEvidence: ${JSON.stringify(evidenceForAi(evidence))}`;
}
function llamaPrompt(evidence, qwen) { return `You are an independent UI color-system judge. Pixel/DOM evidence and a visual auditor disagree or are uncertain. Ignore media photography; decide canvas and repeated interface accents from raw evidence. Do not blindly trust either source. ${schemaPrompt}\nPixel/DOM: ${JSON.stringify(evidenceForAi(evidence))}\nSeparate auditor: ${JSON.stringify(qwen)}`; }
function agrees(a, b) { if (!a || !b || a.primary_surface !== b.primary_surface || a.primary_family !== b.primary_family || a.canvas_mode !== b.canvas_mode) return false; const materialSecondary = Number(b.brand_secondary_score || 0) >= 0.12 || Number(b.brand?.brand_secondary_score || 0) >= 0.12; return !materialSecondary || (!b.secondary_family || a.secondary_family === b.secondary_family); }
function resultFrom(semantic, reason) { const resolved = resolveTone(semantic); return { ...semantic, ...resolved, tone: resolved.tone_group, reason: reason || semantic.reason, needs_review: semantic.needs_review || 'Cần duyệt' === resolved.tone_group }; }
function directResultFrom(semantic, reason) { const review = 'Cần duyệt' === semantic.tone; return { ...semantic, tone_group: semantic.tone, precise_tone: '', tone: semantic.tone, canvas_family: semantic.canvas, primary_surface: semantic.canvas, primary_family: semantic.brand, secondary_family: 'neutral', canvas_mode: ['black', 'charcoal', 'gray', 'navy', 'brown'].includes(semantic.canvas) ? 'dark' : 'light', needs_review: review, reason: reason || semantic.reason }; }
function directNeedsReview(provider = '', model = '', reason = 'Direct Vision was not sufficiently consistent for automatic classification.') { return { tone: 'Cần duyệt', tone_group: 'Cần duyệt', precise_tone: '', confidence: 0, provider, model, reason, needs_review: true }; }
function directConflictsWithEvidence(result, deterministic) { return Boolean(result && deterministic.brand_primary_score >= 0.38 && result.brand !== deterministic.primary_family); }
function serial(error) { return { provider: error.provider || 'unknown', code: error.code || 'PROVIDER_ERROR', status: error.status || 0, quota: !!error.quota, retryable: !!error.retryable, message: String(error.message || '').slice(0, 300) }; }
async function tryProvider(fn, errors) { try { return await fn(); } catch (error) { errors.push(serial(error)); return null; } }

export async function classifyTone({ previewBuffer, evidence, groqApiKey, geminiApiKey, geminiApiKeys = [], geminiDailyBudgetPerKey = GEMINI_DAILY_BUDGET, cloudflareAccount, cloudflareToken, freeOnly = true, autoAccept = AUTO_ACCEPT, classifierMode = 'direct_vision', aiStrategy = 'smart', providers = {}, onProviderStep = null }) {
  if (!freeOnly) throw new ProviderError('FREE_ONLY_REQUIRED', 'Visual-tone classification is locked to FREE_ONLY=true.');
  if (!['off', 'qwen', 'gemini', 'smart'].includes(aiStrategy)) aiStrategy = 'smart';
  const errors = [], directVision = 'direct_vision' === classifierMode, prompt = directVision ? directVisionPrompt() : tonePrompt(evidence), options = { previewBuffer, prompt }, directOptions = directVision ? { responseSchema: DIRECT_VISION_SCHEMA, validateResult: validateDirectVisionResult } : {};
  const groq = providers.groq || classifyWithGroq, cfQwen = providers.cloudflare || classifyWithCloudflareQwen, llama = providers.llama || classifyWithLlamaScout, gemini = providers.gemini || classifyWithGemini;
  const reportStep = async (step, provider) => { try { if (onProviderStep) await onProviderStep({ step, provider }); } catch { /* Telemetry is non-critical. */ } };
  const pixel = evidence.semantic_model || {};
  const deterministic = { canvas_mode: pixel.canvas?.mode || 'light', canvas_family: pixel.canvas?.family || 'white', primary_surface: pixel.canvas?.primary_surface || pixel.canvas?.family || 'white', secondary_surface: pixel.canvas?.secondary_surface || 'other', primary_family: pixel.brand?.brand_primary_family || pixel.primary_accent?.family || 'neutral', secondary_family: pixel.brand?.brand_secondary_family || pixel.secondary_accent?.family || 'neutral', brand_primary_score: pixel.brand?.brand_primary_score || 0, brand_secondary_score: pixel.brand?.brand_secondary_score || 0, confidence: Math.min(pixel.brand?.brand_confidence || 0, pixel.canvas?.surface_confidence || pixel.canvas?.confidence || 0) };
  if ('off' === aiStrategy) return { state: 'needs_review', result: directVision ? directNeedsReview('', '', 'AI strategy is off.') : resultFrom({ ...deterministic, needs_review: true, confidence: 0, reason: 'AI strategy is off.' }), attempts: [], errors, authority: directVision ? 'manual_review' : 'legacy' };

  const today = new Date().toISOString().slice(0, 10);
  const keys = [...geminiApiKeys, geminiApiKey].filter((key, index, values) => key && values.indexOf(key) === index).slice(0, 2).map((key, index) => ({ key, slot: index + 1 }));
  const runGemini = async (judgePrompt, step = 'gemini_judging') => {
    const eligible = keys.map((entry) => {
      const state = geminiPool.get(entry.slot) || { date: today, calls: 0, cooldown: false };
      if (state.date !== today) { state.date = today; state.calls = 0; state.cooldown = false; }
      geminiPool.set(entry.slot, state); return { ...entry, state };
    }).filter((entry) => !entry.state.cooldown && entry.state.calls < geminiDailyBudgetPerKey).sort((a, b) => a.state.calls - b.state.calls);
    for (const entry of eligible) {
      const before = errors.length;
      await reportStep(step, 'gemini');
      const result = await tryProvider(() => gemini({ previewBuffer, prompt: judgePrompt, ...directOptions, apiKey: entry.key }), errors);
      if (result) { entry.state.calls += 1; result.gemini_slot = entry.slot; return result; }
      const failure = errors.slice(before)[0];
      if (failure?.quota || failure?.retryable) entry.state.cooldown = true;
    }
    if (!eligible.length && !keys.length) errors.push(serial(new ProviderError('GEMINI_UNCONFIGURED', 'Gemini API key is not configured.', { provider: 'gemini' })));
    return null;
  };

  let qwen = null;
  if ('qwen' === aiStrategy || 'smart' === aiStrategy) {
    await reportStep('qwen_analyzing', 'qwen');
    qwen = await tryProvider(() => groq({ ...options, ...directOptions, apiKey: groqApiKey }), errors);
    if (!qwen) { await reportStep('qwen_analyzing', 'cloudflare_qwen'); qwen = await tryProvider(() => cfQwen({ ...options, ...directOptions, accountId: cloudflareAccount, apiToken: cloudflareToken }), errors); }
  } else if ('gemini' === aiStrategy) {
    qwen = await runGemini(prompt, 'gemini_analyzing');
  }
  const strongBrandConflict = qwen && deterministic.brand_primary_score >= 0.38 && qwen.primary_family !== deterministic.primary_family;
  const directConflict = directConflictsWithEvidence(qwen, deterministic);
  if (directVision && qwen && 'Cần duyệt' !== qwen.tone && !directConflict && qwen.confidence >= Math.max(0.90, autoAccept)) return { state: 'classified', result: directResultFrom(qwen, 'Direct Vision primary; deterministic colors are coarse sanity evidence only.'), attempts: [qwen], errors, authority: 'direct_vision' };
  if (qwen && !strongBrandConflict && agrees(qwen, deterministic) && qwen.confidence >= autoAccept && !qwen.needs_review) return { state: 'classified', result: resultFrom(qwen, 'Brand + canvas evidence and Qwen agreement.'), attempts: [qwen], errors };
  if ('qwen' === aiStrategy || 'gemini' === aiStrategy) {
    const temporary = errors.length && errors.every((error) => error.retryable || error.quota);
    if (!qwen && temporary) return { state: 'retry_wait', result: null, attempts: [], errors, retry_code: errors.every((error) => error.quota) ? 'FREE_QUOTA_EXHAUSTED' : 'FREE_PROVIDER_UNAVAILABLE' };
    return { state: 'needs_review', result: directVision ? (qwen ? directResultFrom({ ...qwen, confidence: 0, tone: qwen.tone || 'Cần duyệt' }, 'Selected provider could not produce a safe automatic classification.') : directNeedsReview()) : resultFrom(qwen || { ...deterministic, needs_review: true, confidence: 0, reason: 'Selected provider could not produce a confident result.' }), attempts: [qwen].filter(Boolean), errors, ...(directVision ? { authority: 'manual_review' } : {}) };
  }
  await reportStep('llama_judging', 'cloudflare_llama');
  const judgePrompt = directVision ? `${directVisionPrompt()} Act as an independent judge. Re-check the previous answer and return canonical JSON only.` : llamaPrompt(evidence, qwen);
  const scout = await tryProvider(() => llama({ previewBuffer, prompt: judgePrompt, ...directOptions, accountId: cloudflareAccount, apiToken: cloudflareToken }), errors);
  if (directVision) {
    if (scout && 'Cần duyệt' !== scout.tone && !directConflictsWithEvidence(scout, deterministic) && scout.confidence >= JUDGE_ACCEPT) return { state: 'classified', result: directResultFrom(scout, 'Direct Vision judge resolved a primary/evidence contradiction.'), attempts: [qwen, scout].filter(Boolean), errors, authority: 'direct_vision_judge' };
  } else if (scout && scout.confidence >= JUDGE_ACCEPT && !scout.needs_review && !strongBrandConflict && (agrees(scout, qwen) || agrees(scout, deterministic))) return { state: 'classified', result: resultFrom(scout, 'Llama Scout resolved brand + canvas disagreement.'), attempts: [qwen, scout].filter(Boolean), errors };
  const finalJudge = await runGemini(directVision ? `${directVisionPrompt()} Act as the final independent judge. Return canonical JSON only.` : llamaPrompt(evidence, scout || qwen));
  if (finalJudge && finalJudge.confidence >= JUDGE_ACCEPT && (!finalJudge.needs_review || directVision) && (!directVision || ('Cần duyệt' !== finalJudge.tone && !directConflictsWithEvidence(finalJudge, deterministic)))) return { state: 'classified', result: directVision ? directResultFrom(finalJudge, 'Direct Vision final judge resolved the hard disagreement.') : resultFrom(finalJudge, 'Gemini final judge resolved the hard disagreement.'), attempts: [qwen, scout, finalJudge].filter(Boolean), errors, ...(directVision ? { authority: 'direct_vision_judge' } : {}) };
  const temporary = errors.length && errors.every((error) => error.retryable || error.quota);
  if (temporary && !qwen && !scout) return { state: 'retry_wait', result: null, attempts: [], errors, retry_code: errors.every((error) => error.quota) ? 'FREE_QUOTA_EXHAUSTED' : 'FREE_PROVIDER_UNAVAILABLE' };
  const unresolved = finalJudge || scout || qwen;
  return { state: 'needs_review', result: directVision ? (unresolved ? directResultFrom({ ...unresolved, confidence: 0, tone: unresolved.tone || 'Cần duyệt' }, 'Direct Vision contradiction or invalid confidence requires review.') : directNeedsReview()) : resultFrom(unresolved || { ...deterministic, needs_review: true, confidence: 0, provider: '', model: '', reason: 'No confident independent resolution.' }), attempts: [qwen, scout, finalJudge].filter(Boolean), errors };
}
export { ProviderError };
