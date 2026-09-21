import { TONES, CANVAS_FAMILIES, FAMILIES, ProviderError } from './providers/common.mjs';
import { resolveTone } from './tone-map.mjs';

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
  return `You are a senior UI/branding designer. Inspect the complete rendered website screenshot, including its hero, repeated sections and footer. Photos, skin, nails, products and models are content evidence, not brand colors. Readable foreground text (including white text on dark sections) is contrast evidence, never a canvas or brand color. Social-network icons and their native Facebook/Instagram/YouTube/TikTok/X colors are third-party content and must never determine the brand. A lone neutral black/gray/white CTA is a control treatment, not the brand, unless the same neutral is repeated as a genuine design-system accent across independent UI roles and sections. Identify the repeated interface accent first, then the dominant page-level canvas from large background/section surfaces. Return exactly one JSON object with exactly these keys: {"brand":"gold","canvas":"black","tone":"Vàng đen","confidence":0.94,"reason":"short evidence"}. brand must be one of: ${FAMILIES.join('|')}. canvas must be one of: ${CANVAS_FAMILIES.join('|')}. tone must be one of: ${TONES.join('|')}. Tone is brand-first and canvas-second: repeated gold on a dark structural canvas is Vàng đen; repeated gold on a genuinely white structural canvas is Vàng trắng. Use Cần duyệt when evidence is not internally consistent. No markdown, commentary or extra keys. Do not invent labels.`;
}

export function validateDirectVisionResult(value, provider = 'direct_vision', model = '') {
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw new ProviderError('DIRECT_VISION_INVALID_SCHEMA', 'Direct Vision returned an invalid JSON object.', { provider });
  const required = Object.keys(DIRECT_VISION_SCHEMA.properties);
  if (Object.keys(value).length !== required.length || required.some((key) => !(key in value)) || !FAMILIES.includes(value.brand) || !CANVAS_FAMILIES.includes(value.canvas) || !TONES.includes(value.tone) || !Number.isFinite(value.confidence) || value.confidence < 0 || value.confidence > 1 || 'string' !== typeof value.reason || !value.reason.trim()) {
    throw new ProviderError('DIRECT_VISION_INVALID_SCHEMA', 'Direct Vision response is outside the canonical taxonomy.', { provider });
  }
  if ('Cần duyệt' !== value.tone) {
    const dark = ['black', 'charcoal', 'navy', 'brown'].includes(value.canvas);
    const expected = resolveTone({ primary_family: value.brand, primary_surface: value.canvas, canvas_family: value.canvas, canvas_mode: dark ? 'dark' : 'light', needs_review: false }).tone_group;
    if (expected !== value.tone) {
      throw new ProviderError('DIRECT_VISION_SEMANTIC_CONFLICT', `Direct Vision returned ${value.tone}, but brand ${value.brand} on canvas ${value.canvas} maps to ${expected}.`, { provider });
    }
  }
  return { brand: value.brand, canvas: value.canvas, tone: value.tone, confidence: Number(value.confidence.toFixed(3)), reason: value.reason.trim().replace(/\s+/g, ' ').slice(0, 320), provider, model };
}

export function directVisionEligible(item = {}) {
  return Boolean(item.screenshot_url) && !item.diagnostic_screenshot_url && !['HTTP_401', 'HTTP_403', 'CF_CHALLENGE', 'CAPTCHA', 'MAINTENANCE'].includes(String(item.last_error_code || '').toUpperCase());
}
