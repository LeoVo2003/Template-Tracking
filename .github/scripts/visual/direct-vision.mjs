import { TONES, CANVAS_FAMILIES, FAMILIES, ProviderError } from './providers/common.mjs';

export const DIRECT_VISION_MODES = ['legacy', 'benchmark_only', 'direct_vision'];
export const DIRECT_VISION_SCHEMA = {
  type: 'object',
  properties: {
    brand: { type: 'string', enum: FAMILIES },
    canvas: { type: 'string', enum: CANVAS_FAMILIES },
    tone: { type: 'string', enum: TONES },
    confidence: { type: 'number', minimum: 0, maximum: 1 },
    reason: { type: 'string', minLength: 1, maxLength: 320 },
  },
  required: ['brand', 'canvas', 'tone', 'confidence', 'reason'],
  additionalProperties: false,
};

export function directVisionPrompt() {
  return `You are a senior UI/branding designer. Inspect the complete rendered website screenshot, including media and long-page structure. Photos are content evidence, not brand colors. Identify repeated interface accents first, then the dominant page-level canvas. Return JSON only with brand, canvas, tone, confidence, reason. brand and canvas use the canonical taxonomy; tone must be one of: ${TONES.join('|')}. Use Vàng đen for repeated gold on a dark structural canvas, Vàng trắng for repeated gold on a genuinely white structural canvas. Do not invent labels.`;
}

export function validateDirectVisionResult(value, provider = 'direct_vision', model = '') {
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw new ProviderError('DIRECT_VISION_INVALID_SCHEMA', 'Direct Vision returned an invalid JSON object.', { provider });
  const required = Object.keys(DIRECT_VISION_SCHEMA.properties);
  if (Object.keys(value).length !== required.length || required.some((key) => !(key in value)) || !FAMILIES.includes(value.brand) || !CANVAS_FAMILIES.includes(value.canvas) || !TONES.includes(value.tone) || !Number.isFinite(value.confidence) || value.confidence < 0 || value.confidence > 1 || 'string' !== typeof value.reason || !value.reason.trim()) {
    throw new ProviderError('DIRECT_VISION_INVALID_SCHEMA', 'Direct Vision response is outside the canonical taxonomy.', { provider });
  }
  return { brand: value.brand, canvas: value.canvas, tone: value.tone, confidence: Number(value.confidence.toFixed(3)), reason: value.reason.trim().replace(/\s+/g, ' ').slice(0, 320), provider, model };
}

export function directVisionEligible(item = {}) {
  return Boolean(item.screenshot_url) && !item.diagnostic_screenshot_url && !['HTTP_401', 'HTTP_403', 'CF_CHALLENGE', 'CAPTCHA', 'MAINTENANCE'].includes(String(item.last_error_code || '').toUpperCase());
}
