import { REVIEW_TONE, TONE_CATALOG, canonicalizeToneOrder, catalogHas } from './tone-catalog.mjs';
import { ProviderError } from './providers/common.mjs';

export const NAME_TONE_VERSION = 'tone-name-ai2-v2';

export function nameTonePrompt(visionRead, catalog = TONE_CATALOG) {
  return [
    'Bạn là người đặt tên tone web từ bản đọc designer. Không nhìn ảnh. Không đếm pixel. Không ép sang tên “gần nhất”.',
    'Công thức bắt buộc: màu brand/accent đứng TRƯỚC, nền/canvas đứng SAU. Ví dụ vàng trên nền đen = Vàng đen. Không bao giờ Đen vàng, Trắng hồng, Be nâu.',
    'Đen, trắng, xám, be, kem chỉ đứng đầu khi trang gần như đơn sắc, không có accent màu rõ.',
    'Nếu catalog thiếu tên đúng, đề xuất tên mới theo cùng thứ tự ưu tiên (ví dụ vàng be, hồng đen, xanh dương trắng).',
    'Không dùng từ kiểu khói, plush, mist, champagne, sage spa.',
    `Catalog hiện có: ${catalog.join(', ')}.`,
    `Bản đọc AI 1: ${JSON.stringify(visionRead)}`,
    'Trả JSON đúng key: status (pick|propose), tone (tên trong catalog khi pick, rỗng khi propose), proposed_tone (tên mới khi propose, rỗng khi pick), confidence (0..1), reason (một câu tiếng Việt).',
  ].join('\n');
}

export function validateNameToneResult(value, provider = 'groq_text', model = '', catalog = TONE_CATALOG) {
  const fail = (message) => new ProviderError('NAME_TONE_INVALID_SCHEMA', message, { provider });
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw fail('AI 2 returned an invalid JSON object.');
  const status = 'propose' === String(value.status || '').trim() ? 'propose' : 'pick';
  const tone = canonicalizeToneOrder(value.tone);
  const proposed = canonicalizeToneOrder(value.proposed_tone);
  const confidence = Number(value.confidence);
  const reason = String(value.reason || '').trim().replace(/\s+/g, ' ').slice(0, 320);
  if (!Number.isFinite(confidence) || confidence < 0 || confidence > 1 || !reason) throw fail('AI 2 response contains an invalid field.');
  if ('pick' === status && catalogHas(tone, catalog)) {
    return { status: 'pick', tone, proposed_tone: '', confidence: Number(confidence.toFixed(3)), reason, provider, model };
  }
  if ('propose' === status && proposed && REVIEW_TONE !== proposed && !catalogHas(proposed, catalog)) {
    return { status: 'propose', tone: REVIEW_TONE, proposed_tone: proposed, confidence: Number(confidence.toFixed(3)), reason, provider, model };
  }
  // Never snap a missing/invalid pick onto a nearby catalog name.
  return { status: 'propose', tone: REVIEW_TONE, proposed_tone: proposed || tone || '', confidence: Number(confidence.toFixed(3)), reason: reason || 'AI 2 did not land on a catalog name.', provider, model, needs_review: true };
}
