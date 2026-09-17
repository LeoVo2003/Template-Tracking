export const TONES = ['Vàng kem sáng', 'Vàng đen', 'Vàng trắng', 'Đen vàng', 'Hồng xanh trắng', 'Hồng trắng', 'Hồng đen', 'Đỏ trắng', 'Đỏ hồng', 'Đỏ đen', 'Nâu kem', 'Nâu trắng', 'Nâu vàng', 'Nâu đen', 'Xanh vàng', 'Xanh trắng', 'Xanh đen', 'Xanh kem', 'Đen trắng', 'Trắng kem', 'Tím hồng', 'Tím trắng', 'Tím đen', 'Cam trắng', 'Cam đen', 'Cần duyệt'];
export const FAMILIES = ['red', 'pink', 'orange', 'yellow_gold', 'brown', 'green', 'teal', 'blue', 'purple', 'neutral'];
export const CANVAS_FAMILIES = ['white', 'cream', 'gray', 'black'];

export const TONE_SCHEMA = {
  type: 'object',
  properties: {
    canvas_mode: { type: 'string', enum: ['light', 'dark', 'mixed'] },
    canvas_family: { type: 'string', enum: CANVAS_FAMILIES },
    confidence: { type: 'number', minimum: 0, maximum: 1 },
    primary_family: { type: 'string', enum: FAMILIES },
    secondary_family: { type: 'string', enum: FAMILIES },
    style_tone: { type: 'string', enum: ['light_minimal', 'corporate_clean', 'dark_modern', 'vibrant_bold', 'warm_earthy', 'soft_pastel'] },
    reason: { type: 'string', minLength: 1, maxLength: 320 },
    needs_review: { type: 'boolean' },
  },
  required: ['canvas_mode', 'canvas_family', 'primary_family', 'secondary_family', 'style_tone', 'confidence', 'reason', 'needs_review'],
  additionalProperties: false,
};

export class ProviderError extends Error {
  constructor(code, message, { status = 0, retryable = false, quota = false, provider = '' } = {}) {
    super(message);
    this.name = 'ProviderError';
    this.code = code;
    this.status = status;
    this.retryable = retryable;
    this.quota = quota;
    this.provider = provider;
  }
}

export function errorFromResponse(provider, response, body = '') {
  const status = Number(response?.status || 0);
  const summary = String(body || '').replace(/\s+/g, ' ').slice(0, 500);
  if (429 === status || /quota|rate limit|resource exhausted/i.test(summary)) return new ProviderError(`${provider.toUpperCase()}_QUOTA`, `${provider} quota/rate limit: ${summary || status}`, { status, quota: true, retryable: true, provider });
  if (status >= 500 || 408 === status || 504 === status) return new ProviderError(`${provider.toUpperCase()}_UNAVAILABLE`, `${provider} unavailable: HTTP ${status} ${summary}`, { status, retryable: true, provider });
  return new ProviderError(`${provider.toUpperCase()}_HTTP_${status || 'ERROR'}`, `${provider} rejected the request: HTTP ${status} ${summary}`, { status, provider });
}

export function parseJsonStrict(value, provider) {
  if (value && 'object' === typeof value && !Array.isArray(value)) return value;
  if ('string' !== typeof value) throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} returned no JSON object.`, { provider });
  try { return JSON.parse(value); } catch { throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} returned invalid JSON.`, { provider }); }
}

export function validateToneResult(value, provider, model) {
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} returned an invalid result object.`, { provider });
  const expected = Object.keys(TONE_SCHEMA.properties);
  if (Object.keys(value).length !== expected.length || expected.some((key) => !(key in value))) throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} response does not match the required tone schema.`, { provider });
  if (!CANVAS_FAMILIES.includes(value.canvas_family) || !FAMILIES.includes(value.primary_family) || !FAMILIES.includes(value.secondary_family) || !['light', 'dark', 'mixed'].includes(value.canvas_mode) || 'boolean' !== typeof value.needs_review || !Number.isFinite(value.confidence) || value.confidence < 0 || value.confidence > 1 || 'string' !== typeof value.reason || !value.reason.trim()) {
    throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} response contains an invalid tone field.`, { provider });
  }
  return {
    canvas_mode: value.canvas_mode,
    canvas_family: value.canvas_family,
    confidence: Number(value.confidence.toFixed(3)),
    primary_family: value.primary_family,
    secondary_family: value.secondary_family,
    style_tone: value.style_tone,
    reason: value.reason.trim().replace(/\s+/g, ' ').slice(0, 320),
    needs_review: value.needs_review,
    provider,
    model,
  };
}

export function confidenceLabel(value) {
  return value >= 0.85 ? 'high' : (value >= 0.75 ? 'medium' : 'low');
}
