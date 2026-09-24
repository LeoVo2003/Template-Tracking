import { classifyWithGroq } from './providers/groq-qwen.mjs';
import { classifyWithCloudflareQwen } from './providers/cloudflare-qwen.mjs';
import { classifyWithLlamaScout } from './providers/cloudflare-llama-scout.mjs';
import { classifyWithGemini } from './providers/gemini.mjs';
import { ProviderError, FAMILIES, CANVAS_FAMILIES } from './providers/common.mjs';
import { resolveTone } from './tone-map.mjs';
import { DIRECT_VISION_SCHEMA, directVisionJudgePrompt, directVisionPrompt, validateDirectVisionResult } from './direct-vision.mjs';

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

const CANVAS_GROUPS = {
  light_white: ['white', 'ivory'],
  light_warm: ['cream', 'beige', 'greige'],
  light_gray: ['gray'],
  dark: ['black', 'charcoal'],
  dark_blue: ['navy'],
  warm_dark: ['brown'],
  warm_accent: ['gold', 'yellow'],
};
const BRAND_GROUPS = {
  warm_metallic: ['gold', 'champagne', 'yellow'],
  warm_earth: ['brown', 'taupe'],
  pink: ['pink', 'rose', 'dusty_rose'],
  red: ['red', 'burgundy', 'terracotta'],
  orange: ['orange', 'peach'],
  blue: ['blue', 'navy', 'teal', 'aqua'],
  green: ['green', 'sage', 'olive'],
  light_neutral: ['white', 'ivory', 'cream', 'beige', 'greige'],
  dark_neutral: ['black', 'charcoal', 'gray'],
  purple: ['purple', 'lavender'],
};

function familyGroup(family, groups) { return Object.entries(groups).find(([, values]) => values.includes(String(family || '')))?.[0] || ''; }
function canvasModeForFamily(family) { return ['black', 'charcoal', 'navy', 'brown'].includes(String(family || '')) ? 'dark' : 'light'; }
export function canvasCompatible(vision, deterministic) {
  if (!vision || !deterministic || vision === deterministic) return Boolean(vision && deterministic);
  const a = familyGroup(vision, CANVAS_GROUPS), b = familyGroup(deterministic, CANVAS_GROUPS);
  if (a && a === b) return true;
  const light = ['light_white', 'light_warm', 'light_gray'];
  if (light.includes(a) && light.includes(b)) return true;
  return ('warm_accent' === a && ['light_white', 'light_warm'].includes(b)) || ('warm_accent' === b && ['light_white', 'light_warm'].includes(a));
}
export function brandCompatibility(vision, deterministic) {
  if (!vision || !deterministic) return 'unknown';
  if (vision === deterministic) return 'exact';
  const a = familyGroup(vision, BRAND_GROUPS), b = familyGroup(deterministic, BRAND_GROUPS);
  if (a && a === b) return 'compatible';
  const pair = new Set([a, b]);
  if ((pair.has('warm_metallic') && pair.has('warm_earth')) || (pair.has('pink') && pair.has('red'))) return 'nearby';
  return 'incompatible';
}

export function brandEvidenceStrength(candidate = {}) {
  const score = Number(candidate.score ?? candidate.brand_primary_score ?? 0);
  const roles = [...new Set((candidate.roles || []).map(String).filter(Boolean))];
  const sources = Array.isArray(candidate.sources) ? candidate.sources : [];
  const sourceSections = new Set(sources.map((source) => String(source?.section_key || '')).filter(Boolean));
  const sourceRoles = new Set(sources.map((source) => String(source?.role || '')).filter(Boolean));
  const sectionCount = Math.max(Number(candidate.section_count || 0), sourceSections.size);
  const roleCount = Math.max(roles.length, sourceRoles.size);
  const controlCount = Number(candidate.control_count || 0);
  const themeToken = roles.includes('token_accent') || sources.some((source) => 'token_accent' === source?.role);
  const recurrentProvenance = sources.length >= 2 && (sourceSections.size >= 2 || sourceRoles.size >= 2);
  const meaningful = score >= 0.18 && (sectionCount >= 2 || roleCount >= 2 || themeToken || recurrentProvenance || controlCount >= 3);
  return { meaningful, level: meaningful ? (score >= 0.38 || themeToken ? 'strong' : 'material') : 'weak', score, section_count: sectionCount, role_count: roleCount, control_count: controlCount, roles, theme_token: themeToken, provenance_count: sources.length, recurrent_provenance: recurrentProvenance };
}

function deterministicFromEvidence(pixel = {}) {
  const canvas = pixel.canvas || {}, brand = pixel.brand || {};
  const primaryFamily = brand.brand_primary_family || pixel.primary_accent?.family || 'neutral';
  const candidate = (brand.brand_evidence || []).find((entry) => entry.family === primaryFamily) || { family: primaryFamily, score: brand.brand_primary_score || pixel.primary_accent?.score || 0, roles: [], section_count: 0, control_count: 0, sources: [], hex: pixel.primary_accent?.hex || '' };
  return {
    canvas_mode: canvas.mode || 'light',
    canvas_family: canvas.family || canvas.primary_surface || 'white',
    primary_surface: canvas.primary_surface || canvas.family || 'white',
    secondary_surface: canvas.secondary_surface || 'other',
    surface_confidence: Number(canvas.surface_confidence ?? canvas.confidence ?? 0),
    light_surface_ratio: Number(canvas.light_surface_ratio || 0),
    dark_surface_ratio: Number(canvas.dark_surface_ratio || 0),
    primary_family: primaryFamily,
    secondary_family: brand.brand_secondary_family || pixel.secondary_accent?.family || 'neutral',
    brand_primary_score: Number(brand.brand_primary_score || candidate.score || 0),
    brand_secondary_score: Number(brand.brand_secondary_score || 0),
    brand_candidate: { ...candidate, family: primaryFamily },
    confidence: Math.min(Number(brand.brand_confidence || 0), Number(canvas.surface_confidence ?? canvas.confidence ?? 0)),
  };
}

export function evaluateDirectVisionConflict(result, deterministic = {}, evidence = {}) {
  const pixel = evidence?.semantic_model || evidence || {};
  const source = deterministic.primary_surface ? deterministic : deterministicFromEvidence(pixel);
  const canvasFamily = source.primary_surface || source.canvas_family || 'white';
  const canvasMode = source.canvas_mode || 'light';
  const canvasConfidence = Number(source.surface_confidence ?? source.canvas_confidence ?? source.confidence ?? 0);
  const candidate = source.brand_candidate || (pixel.brand?.brand_evidence || []).find((entry) => entry.family === source.primary_family) || { family: source.primary_family || 'neutral', score: source.brand_primary_score || 0 };
  const strength = brandEvidenceStrength(candidate);
  const deterministicSummary = {
    canvas: { family: canvasFamily, mode: canvasMode, confidence: canvasConfidence, primary_surface: source.primary_surface || canvasFamily, secondary_surface: source.secondary_surface || '', light_surface_ratio: Number(source.light_surface_ratio || 0), dark_surface_ratio: Number(source.dark_surface_ratio || 0) },
    brand: { family: candidate.family || source.primary_family || 'neutral', hex: candidate.hex || '', score: strength.score, sections: strength.section_count, roles: strength.roles, role_count: strength.role_count, controls: strength.control_count, theme_token: strength.theme_token, provenance_count: strength.provenance_count, strength: strength.level },
  };
  if (!result) return { has_conflict: false, severity: 'none', canvas_conflict: false, brand_conflict: false, reasons: [], deterministic_summary: deterministicSummary };
  const reasons = [];
  const visionCanvas = result.canvas || result.primary_surface || result.canvas_family || '';
  const visionBrand = result.brand || result.primary_family || '';
  const canvasMismatch = Boolean(visionCanvas) && (!canvasCompatible(visionCanvas, canvasFamily) || (['light', 'dark'].includes(canvasMode) && canvasModeForFamily(visionCanvas) !== canvasMode));
  const hardCanvas = canvasMismatch && canvasConfidence >= 0.85;
  if (canvasMismatch) reasons.push(`Vision canvas ${visionCanvas} conflicts with deterministic ${canvasFamily}/${canvasMode} canvas at ${canvasConfidence.toFixed(2)} confidence.`);
  const compatibility = brandCompatibility(visionBrand, deterministicSummary.brand.family);
  const brandConflict = strength.meaningful && 'incompatible' === compatibility;
  if (brandConflict) reasons.push(`Vision brand ${visionBrand} conflicts with repeated deterministic ${deterministicSummary.brand.family} UI evidence (score ${strength.score.toFixed(4)}, sections ${strength.section_count}, roles ${strength.roles.join(', ') || 'unknown'}).`);
  return {
    has_conflict: canvasMismatch || brandConflict,
    severity: hardCanvas ? 'hard' : ((canvasMismatch || brandConflict) ? 'soft' : 'none'),
    canvas_conflict: canvasMismatch,
    brand_conflict: brandConflict,
    reasons,
    deterministic_summary: deterministicSummary,
    brand_compatibility: compatibility,
  };
}

function materialDirectConflict(conflict) { return Boolean(conflict && ('hard' === conflict.severity || conflict.brand_conflict)); }
function judgeContext(conflict, primary, threshold) {
  if (conflict?.reasons?.length) return conflict;
  return { ...(conflict || {}), reasons: [primary ? `Primary Vision confidence ${Number(primary.confidence || 0).toFixed(2)} was below the ${Number(threshold).toFixed(2)} automatic threshold.` : 'The primary Vision provider did not return a usable canonical result.'] };
}
function resolutionReason(conflict, stage = 'judge') {
  const prefix = 'final' === stage ? 'Direct Vision final judge' : 'Direct Vision judge';
  if ('hard' === conflict?.severity && conflict.canvas_conflict) return `${prefix} resolved a hard canvas contradiction.`;
  if (conflict?.brand_conflict) return `${prefix} resolved a repeated-brand disagreement.`;
  return `${prefix} confirmed a low-confidence primary decision without a material sanity conflict.`;
}
function resolvedConflict(conflict, evaluator, authority) { return { ...conflict, resolved: true, resolved_by: authority, resolution_evaluation: evaluator }; }
function unresolvedConflict(conflict, evaluator = null) { return { ...conflict, resolved: false, ...(evaluator ? { resolution_evaluation: evaluator } : {}) }; }
function serial(error) { return { provider: error.provider || 'unknown', code: error.code || 'PROVIDER_ERROR', status: error.status || 0, quota: !!error.quota, retryable: !!error.retryable, message: String(error.message || '').slice(0, 300) }; }
async function tryProvider(fn, errors) { try { return await fn(); } catch (error) { errors.push(serial(error)); return null; } }

export async function classifyTone({ previewBuffer, evidence, groqApiKey, geminiApiKey, geminiApiKeys = [], geminiDailyBudgetPerKey = GEMINI_DAILY_BUDGET, cloudflareAccount, cloudflareToken, freeOnly = true, autoAccept = AUTO_ACCEPT, classifierMode = 'direct_vision', aiStrategy = 'smart', providers = {}, onProviderStep = null }) {
  if (!freeOnly) throw new ProviderError('FREE_ONLY_REQUIRED', 'Visual-tone classification is locked to FREE_ONLY=true.');
  if (!['off', 'qwen', 'gemini', 'smart'].includes(aiStrategy)) aiStrategy = 'smart';
  const errors = [], directVision = 'direct_vision' === classifierMode, prompt = directVision ? directVisionPrompt() : tonePrompt(evidence), options = { previewBuffer, prompt }, directOptions = directVision ? { responseSchema: DIRECT_VISION_SCHEMA, validateResult: validateDirectVisionResult } : {};
  const groq = providers.groq || classifyWithGroq, cfQwen = providers.cloudflare || classifyWithCloudflareQwen, llama = providers.llama || classifyWithLlamaScout, gemini = providers.gemini || classifyWithGemini;
  const reportStep = async (step, provider) => { try { if (onProviderStep) await onProviderStep({ step, provider }); } catch { /* Telemetry is non-critical. */ } };
  const pixel = evidence?.semantic_model || {};
  const deterministic = deterministicFromEvidence(pixel);
  if ('off' === aiStrategy) return { state: 'needs_review', result: directVision ? directNeedsReview('', '', 'AI strategy is off.') : resultFrom({ ...deterministic, needs_review: true, confidence: 0, reason: 'AI strategy is off.' }), attempts: [], errors, authority: directVision ? 'manual_review' : 'legacy', ...(directVision ? { conflict: evaluateDirectVisionConflict(null, deterministic, evidence) } : {}) };

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
  const primaryConflict = directVision ? evaluateDirectVisionConflict(qwen, deterministic, evidence) : null;
  const directThreshold = Math.max(0.90, autoAccept);
  if (directVision && qwen && 'Cần duyệt' !== qwen.tone && !materialDirectConflict(primaryConflict) && qwen.confidence >= directThreshold) {
    return { state: 'classified', result: directResultFrom(qwen, 'Direct Vision primary accepted; no material sanity conflict.'), attempts: [qwen], errors, authority: 'direct_vision', conflict: { ...primaryConflict, resolved: true, resolved_by: 'direct_vision' } };
  }
  if ('qwen' === aiStrategy || 'gemini' === aiStrategy) {
    const temporary = errors.length && errors.every((error) => error.retryable || error.quota);
    if (!qwen && temporary) return { state: 'retry_wait', result: null, attempts: [], errors, retry_code: errors.every((error) => error.quota) ? 'FREE_QUOTA_EXHAUSTED' : 'FREE_PROVIDER_UNAVAILABLE' };
    return { state: 'needs_review', result: directVision ? (qwen ? directResultFrom({ ...qwen, confidence: 0, tone: qwen.tone || 'Cần duyệt' }, materialDirectConflict(primaryConflict) ? 'Direct Vision remained contradictory and requires review.' : 'Selected provider did not reach the automatic confidence threshold.') : directNeedsReview()) : resultFrom(qwen || { ...deterministic, needs_review: true, confidence: 0, reason: 'Selected provider could not produce a confident result.' }), attempts: [qwen].filter(Boolean), errors, ...(directVision ? { authority: 'manual_review', conflict: unresolvedConflict(primaryConflict) } : {}) };
  }

  if (directVision) {
    const suppliedConflict = judgeContext(primaryConflict, qwen, directThreshold);
    await reportStep('llama_judging', 'cloudflare_llama');
    const judgePrompt = directVisionJudgePrompt({ previousResult: qwen, deterministic: primaryConflict?.deterministic_summary || evaluateDirectVisionConflict(null, deterministic, evidence).deterministic_summary, conflict: suppliedConflict });
    const scout = await tryProvider(() => llama({ previewBuffer, prompt: judgePrompt, ...directOptions, accountId: cloudflareAccount, apiToken: cloudflareToken }), errors);
    const judgeConflict = evaluateDirectVisionConflict(scout, deterministic, evidence);
    if (scout && 'Cần duyệt' !== scout.tone && scout.confidence >= JUDGE_ACCEPT && !materialDirectConflict(judgeConflict)) {
      return { state: 'classified', result: directResultFrom(scout, resolutionReason(primaryConflict, 'judge')), attempts: [qwen, scout].filter(Boolean), errors, authority: 'direct_vision_judge', conflict: resolvedConflict(primaryConflict, judgeConflict, 'direct_vision_judge') };
    }
    const finalConflictContext = {
      ...suppliedConflict,
      severity: 'hard' === primaryConflict?.severity || 'hard' === judgeConflict.severity ? 'hard' : (primaryConflict?.has_conflict || judgeConflict.has_conflict ? 'soft' : 'none'),
      reasons: [...(suppliedConflict.reasons || []), ...(judgeConflict.reasons || []).map((reason) => `Vision judge remained inconsistent: ${reason}`)],
    };
    const finalPrompt = directVisionJudgePrompt({ previousResult: qwen, judgeResult: scout, deterministic: primaryConflict?.deterministic_summary || judgeConflict.deterministic_summary, conflict: finalConflictContext, final: true });
    const finalJudge = await runGemini(finalPrompt);
    const finalConflict = evaluateDirectVisionConflict(finalJudge, deterministic, evidence);
    if (finalJudge && 'Cần duyệt' !== finalJudge.tone && finalJudge.confidence >= JUDGE_ACCEPT && !materialDirectConflict(finalConflict)) {
      return { state: 'classified', result: directResultFrom(finalJudge, resolutionReason(primaryConflict, 'final')), attempts: [qwen, scout, finalJudge].filter(Boolean), errors, authority: 'direct_vision_final_judge', conflict: resolvedConflict(primaryConflict, finalConflict, 'direct_vision_final_judge') };
    }
    const temporary = errors.length && errors.every((error) => error.retryable || error.quota);
    if (temporary && !qwen && !scout) return { state: 'retry_wait', result: null, attempts: [], errors, retry_code: errors.every((error) => error.quota) ? 'FREE_QUOTA_EXHAUSTED' : 'FREE_PROVIDER_UNAVAILABLE', conflict: unresolvedConflict(primaryConflict, finalConflict) };
    const unresolved = finalJudge || scout || qwen;
    const reviewReason = materialDirectConflict(primaryConflict) || materialDirectConflict(judgeConflict) || materialDirectConflict(finalConflict) ? 'Direct Vision remained contradictory and requires review.' : 'Direct Vision did not reach a consistent high-confidence result and requires review.';
    return { state: 'needs_review', result: unresolved ? directResultFrom({ ...unresolved, confidence: 0, tone: unresolved.tone || 'Cần duyệt' }, reviewReason) : directNeedsReview('', '', reviewReason), attempts: [qwen, scout, finalJudge].filter(Boolean), errors, authority: 'manual_review', conflict: unresolvedConflict(primaryConflict, finalConflict) };
  }

  const strongBrandConflict = qwen && deterministic.brand_primary_score >= 0.38 && qwen.primary_family !== deterministic.primary_family;
  if (qwen && !strongBrandConflict && agrees(qwen, deterministic) && qwen.confidence >= autoAccept && !qwen.needs_review) return { state: 'classified', result: resultFrom(qwen, 'Brand + canvas evidence and Qwen agreement.'), attempts: [qwen], errors };
  await reportStep('llama_judging', 'cloudflare_llama');
  const judgePrompt = llamaPrompt(evidence, qwen);
  const scout = await tryProvider(() => llama({ previewBuffer, prompt: judgePrompt, ...directOptions, accountId: cloudflareAccount, apiToken: cloudflareToken }), errors);
  if (scout && scout.confidence >= JUDGE_ACCEPT && !scout.needs_review && !strongBrandConflict && (agrees(scout, qwen) || agrees(scout, deterministic))) return { state: 'classified', result: resultFrom(scout, 'Llama Scout resolved brand + canvas disagreement.'), attempts: [qwen, scout].filter(Boolean), errors };
  const finalJudge = await runGemini(llamaPrompt(evidence, scout || qwen));
  if (finalJudge && finalJudge.confidence >= JUDGE_ACCEPT && !finalJudge.needs_review) return { state: 'classified', result: resultFrom(finalJudge, 'Gemini final judge resolved the hard disagreement.'), attempts: [qwen, scout, finalJudge].filter(Boolean), errors };
  const temporary = errors.length && errors.every((error) => error.retryable || error.quota);
  if (temporary && !qwen && !scout) return { state: 'retry_wait', result: null, attempts: [], errors, retry_code: errors.every((error) => error.quota) ? 'FREE_QUOTA_EXHAUSTED' : 'FREE_PROVIDER_UNAVAILABLE' };
  const unresolved = finalJudge || scout || qwen;
  return { state: 'needs_review', result: resultFrom(unresolved || { ...deterministic, needs_review: true, confidence: 0, provider: '', model: '', reason: 'No confident independent resolution.' }), attempts: [qwen, scout, finalJudge].filter(Boolean), errors };
}
export { ProviderError };
