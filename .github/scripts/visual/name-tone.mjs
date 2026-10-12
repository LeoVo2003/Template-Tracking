import { REVIEW_TONE, TONE_CATALOG, catalogHas, simplifyToneName } from './tone-catalog.mjs';
import { ProviderError } from './providers/common.mjs';

export const NAME_TONE_VERSION = 'tone-name-ai2-v3';

export function nameTonePrompt(visionRead, catalog = TONE_CATALOG) {
  return [
    'Bạn là người đặt tên tone web từ bản đọc designer. Không nhìn ảnh. Không đếm pixel.',
    'Chỉ dùng ĐÚNG 2 từ: màu brand/accent + hậu tố nền. Ví dụ Nâu kem, Vàng đen, Hồng trắng.',
    'Nude, taupe, ivory, cream = nâu hoặc kem/be. Không viết Nâu taupe kem, Nude ấm, kem sữa.',
    'Không dùng từ kiểu taupe, nude, khói, plush, mist, champagne, sage, spa.',
    'Ưu tiên pick một tên có trong catalog. Chỉ propose khi catalog thật sự thiếu cặp màu đó.',
    `Catalog hiện có: ${catalog.join(', ')}.`,
    `Bản đọc AI 1: ${JSON.stringify(visionRead)}`,
    'Trả JSON đúng key: status (pick|propose), tone (tên trong catalog khi pick, rỗng khi propose), proposed_tone (tên mới khi propose, rỗng khi pick), confidence (0..1), reason (một câu tiếng Việt).',
  ].join('\n');
}

export function validateNameToneResult(value, provider = 'groq_text', model = '', catalog = TONE_CATALOG) {
  const fail = (message) => new ProviderError('NAME_TONE_INVALID_SCHEMA', message, { provider });
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw fail('AI 2 returned an invalid JSON object.');
  const confidence = Number(value.confidence);
  const reason = String(value.reason || '').trim().replace(/\s+/g, ' ').slice(0, 320);
  if (!Number.isFinite(confidence) || confidence < 0 || confidence > 1 || !reason) throw fail('AI 2 response contains an invalid field.');
  const cleaned = simplifyToneName(value.tone || value.proposed_tone);
  if (catalogHas(cleaned, catalog)) {
    return { status: 'pick', tone: cleaned, proposed_tone: '', confidence: Number(confidence.toFixed(3)), reason, provider, model };
  }
  if (cleaned && REVIEW_TONE !== cleaned) {
    return { status: 'propose', tone: REVIEW_TONE, proposed_tone: cleaned, confidence: Number(confidence.toFixed(3)), reason, provider, model, needs_review: true };
  }
  return { status: 'propose', tone: REVIEW_TONE, proposed_tone: '', confidence: Number(confidence.toFixed(3)), reason: reason || 'AI 2 did not land on a catalog name.', provider, model, needs_review: true };
}
