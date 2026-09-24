// Stored `tone` remains the compact group. Older values stay valid forever.
export const TONES = ['Vàng kem sáng', 'Vàng kem', 'Vàng be', 'Vàng nâu', 'Vàng đen', 'Vàng trắng', 'Đen vàng', 'Đen trắng', 'Đen xám', 'Hồng xanh trắng', 'Hồng trắng', 'Hồng kem', 'Hồng be', 'Hồng xám', 'Hồng nâu', 'Hồng đen', 'Đỏ trắng', 'Đỏ kem', 'Đỏ be', 'Đỏ hồng', 'Đỏ nâu', 'Đỏ đen', 'Nâu kem', 'Nâu trắng', 'Nâu be', 'Nâu vàng', 'Nâu xám', 'Nâu đen', 'Xanh vàng', 'Xanh trắng', 'Xanh đen', 'Xanh kem', 'Trắng kem', 'Trắng be', 'Trắng xám', 'Kem trắng', 'Kem be', 'Kem xám', 'Kem nâu', 'Be trắng', 'Be kem', 'Be xám', 'Be nâu', 'Xám trắng', 'Xám kem', 'Xám be', 'Xám nâu', 'Xám đen', 'Tím hồng', 'Tím trắng', 'Tím kem', 'Tím be', 'Tím xám', 'Tím đen', 'Cam trắng', 'Cam kem', 'Cam be', 'Cam nâu', 'Cam đen', 'Cần duyệt'];
export const FAMILIES = ['white', 'ivory', 'cream', 'beige', 'greige', 'gray', 'charcoal', 'black', 'taupe', 'brown', 'terracotta', 'orange', 'peach', 'yellow', 'gold', 'champagne', 'red', 'burgundy', 'pink', 'rose', 'dusty_rose', 'purple', 'lavender', 'green', 'olive', 'sage', 'blue', 'navy', 'teal', 'aqua', 'neutral'];
export const CANVAS_FAMILIES = ['white', 'ivory', 'cream', 'beige', 'greige', 'gray', 'charcoal', 'black', 'navy', 'brown', 'gold', 'yellow', 'other'];

export const TONE_SCHEMA = {
  type: 'object',
  properties: {
    canvas_mode: { type: 'string', enum: ['light', 'dark', 'mixed'] },
    canvas_family: { type: 'string', enum: CANVAS_FAMILIES },
    primary_surface: { type: 'string', enum: CANVAS_FAMILIES },
    secondary_surface: { type: 'string', enum: CANVAS_FAMILIES },
    confidence: { type: 'number', minimum: 0, maximum: 1 },
    primary_family: { type: 'string', enum: FAMILIES },
    secondary_family: { type: 'string', enum: FAMILIES },
    style_tone: { type: 'string', enum: ['light_minimal', 'corporate_clean', 'dark_modern', 'vibrant_bold', 'warm_earthy', 'soft_pastel'] },
    reason: { type: 'string', minLength: 1, maxLength: 320 },
    needs_review: { type: 'boolean' },
  },
  required: ['canvas_mode', 'canvas_family', 'primary_surface', 'secondary_surface', 'primary_family', 'secondary_family', 'style_tone', 'confidence', 'reason', 'needs_review'],
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
  const trimmed = value.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '');
  try { return JSON.parse(trimmed); } catch { /* scan embedded JSON below */ }
  // Some free reasoning models wrap the object in analysis text or emit more
  // than one brace-delimited fragment. Parse the first complete valid object,
  // respecting quoted strings, without accepting a partial/truncated object.
  for (let start = trimmed.indexOf('{'); start >= 0; start = trimmed.indexOf('{', start + 1)) {
    let depth = 0, quoted = false, escaped = false;
    for (let index = start; index < trimmed.length; index += 1) {
      const char = trimmed[index];
      if (quoted) {
        if (escaped) escaped = false;
        else if ('\\' === char) escaped = true;
        else if ('"' === char) quoted = false;
        continue;
      }
      if ('"' === char) { quoted = true; continue; }
      if ('{' === char) depth += 1;
      if ('}' === char) depth -= 1;
      if (0 === depth) {
        try { return JSON.parse(trimmed.slice(start, index + 1)); } catch { break; }
      }
    }
  }
  throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} returned invalid JSON.`, { provider });
}

/** Normalize supported OpenAI-compatible and Workers AI response shapes. */
export function extractStructuredContent(payload, provider) {
  const root = payload?.result || payload;
  const message = root?.choices?.[0]?.message || root?.message || {};
  if (message.parsed && 'object' === typeof message.parsed) return message.parsed;
  if (message.content && 'object' === typeof message.content && !Array.isArray(message.content)) return message.content;
  if (Array.isArray(message.content)) {
    const text = message.content.map((part) => part?.text || part?.content || '').filter(Boolean).join('');
    if (text) return parseJsonStrict(text, provider);
  }
  // Cloudflare reasoning models can place the complete JSON object in
  // `reasoning` while returning a null `content` field.
  const candidate = message.content ?? message.reasoning ?? root?.response ?? root?.result?.response ?? root?.output_text;
  return parseJsonStrict(candidate, provider);
}

/** Describe response containers without leaking generated text or image data. */
export function describeStructuredShape(value, depth = 0) {
  if (null === value) return 'null';
  if (Array.isArray(value)) {
    if (depth >= 3 || 0 === value.length) return `array(${value.length})`;
    return { type: `array(${value.length})`, first: describeStructuredShape(value[0], depth + 1) };
  }
  if ('object' !== typeof value) return typeof value;
  if (depth >= 3) return 'object';
  return Object.fromEntries(Object.entries(value).slice(0, 20).map(([key, child]) => [key, describeStructuredShape(child, depth + 1)]));
}

export function validateToneResult(value, provider, model) {
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} returned an invalid result object.`, { provider });
  const expected = Object.keys(TONE_SCHEMA.properties);
  if (Object.keys(value).length !== expected.length || expected.some((key) => !(key in value))) throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} response does not match the required tone schema.`, { provider });
  if (!CANVAS_FAMILIES.includes(value.canvas_family) || !CANVAS_FAMILIES.includes(value.primary_surface) || !CANVAS_FAMILIES.includes(value.secondary_surface) || !FAMILIES.includes(value.primary_family) || !FAMILIES.includes(value.secondary_family) || !['light', 'dark', 'mixed'].includes(value.canvas_mode) || 'boolean' !== typeof value.needs_review || !Number.isFinite(value.confidence) || value.confidence < 0 || value.confidence > 1 || 'string' !== typeof value.reason || !value.reason.trim()) {
    throw new ProviderError(`${provider.toUpperCase()}_INVALID_SCHEMA`, `${provider} response contains an invalid tone field.`, { provider });
  }
  return {
    canvas_mode: value.canvas_mode,
    canvas_family: value.canvas_family,
    primary_surface: value.primary_surface,
    secondary_surface: value.secondary_surface,
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
