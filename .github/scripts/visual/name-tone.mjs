import { REVIEW_TONE, TONE_CATALOG, catalogHas, officialToneName } from './tone-catalog.mjs';
import { ProviderError } from './providers/common.mjs';

export const NAME_TONE_VERSION = 'tone-name-ai2-v5';

/**
 * Deterministic companion hint: find a chromatic secondary canvas in
 * design_colors (role hints at phụ/section/block, not the main background)
 * and tell AI2 directly which family it is. Small models fail at the
 * multi-step "compare hex → find secondary → combine" reasoning, so the
 * code does it and the prompt just states the result.
 */
function familyFromHex(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!m) return '';
  const r = parseInt(m[1].slice(0, 2), 16);
  const g = parseInt(m[1].slice(2, 4), 16);
  const b = parseInt(m[1].slice(4, 6), 16);
  if (Math.max(r, g, b) - Math.min(r, g, b) < 20) return '';
  if (b >= r && b >= g && b - r > 10) return 'XANH';
  if (g >= r && g >= b && g - r > 10) return 'XANH LÁ';
  if (r >= g && r >= b && r - b > 30) return 'HỒNG';
  if (r > b && r - b > 10) return 'KEM';
  return '';
}

function companionHint(visionRead) {
  const colors = Array.isArray(visionRead?.design_colors) ? visionRead.design_colors : [];
  // 1) Section/block secondary (existing logic).
  const secondary = colors.find((c) => {
    const role = String(c?.role || '').toLowerCase();
    if (!/phụ|phu|section|block/.test(role)) return false;
    if (/chính|chinh|main/.test(role)) return false;
    return !!familyFromHex(c?.hex);
  });
  if (secondary) {
    const family = familyFromHex(secondary.hex);
    return `CHỈ ĐỊNH MÀU ĐI KÈM: ${family} (${secondary.hex}, vai trò "${secondary.role}") — màu đi kèm là ${family}, KHÔNG phải nền chính. Tên 2 từ phải có ${family} ở vị trí thứ hai.`;
  }
  // 2) Accent colors: AI1 may mention red/pink in text (e.g. "điểm đỏ gạch")
  // without a structured hex. If the vision text names a chromatic accent,
  // tell AI2 directly so it weighs the accent instead of defaulting to white.
  const text = JSON.stringify(visionRead || {}).toLowerCase();
  const accentRed = /đỏ|red|#c2185b|#ff0101|crimson/.test(text);
  const accentBlue = /xanh dương|#1ca0e0|#1e90ff|electric blue/.test(text);
  if (accentRed && accentBlue) {
    return `CHỈ ĐỊNH MÀU ĐI KÈM: ĐỎ — bản đọc AI1 ghi rõ có điểm nhấn đỏ (đỏ gạch/crimson) đi cùng xanh dương chính. Đỏ là màu đi kèm quan trọng, KHÔNG được bỏ qua. Tên 2 từ phải có ĐỎ ở vị trí thứ hai (ví dụ Xanh dương đỏ).`;
  }
  // 3) Check design_colors for accent-role reds.
  const accent = colors.find((c) => {
    const role = String(c?.role || '').toLowerCase();
    const hex = String(c?.hex || '').toUpperCase();
    if (!/accent|cta|button|tiêu đề|heading|điểm nhấn|nhấn/.test(role)) return false;
    return /^#(C2185B|FF0101|D32F2F|E53935)/.test(hex) || /đỏ|red/.test(role);
  });
  if (accent) {
    return `CHỈ ĐỊNH MÀU ĐI KÈM: ĐỎ (${accent.hex}, vai trò "${accent.role}") — đỏ là accent lặp lại (tiêu đề/nút), phải thể hiện trong tên. Tên 2 từ phải có ĐỎ ở vị trí thứ hai.`;
  }
  return '';
}

export function nameTonePrompt(visionRead, catalog = TONE_CATALOG) {
  const hint = companionHint(visionRead);
  return [
    'Bạn là người đặt tên tone web từ bản đọc designer. Không nhìn ảnh. Không đếm pixel.',
    'Trang chỉ 1 giọng màu (suông) → 1 từ: Nâu / Be / Nude (nude/kem/be suông = Nude hoặc Be; nâu ấm suông = Nâu). Trang có 2 giọng rõ rệt (brand + nền/accent khác hẳn) → BẮT BUỘC 2 từ cụ thể, ví dụ Nâu kem, Nâu trắng, Be kem — không được gộp thành 1 từ.',
    'Phân biệt Be và Nâu: trang kem/be sáng suông, KHÔNG có mảng nâu đất hay nâu socola rõ rệt → Be (hoặc Be kem), TUYỆT ĐỐI không gọi Nâu. Chỉ gọi Nâu khi có màu nâu thật (nâu đất, nâu socola, từ #8C5A3C trở đậm) làm brand hoặc mảng nền lớn.',
    'Phân biệt Đỏ và Hồng: raspberry, hồng đậm, terracotta, hồng đất, dusty rose, hồng phấn, hồng nude, mauve → HỒNG, không phải Đỏ. Chỉ gọi Đỏ khi là đỏ tươi/thuần rực rỡ (pure red).',
    'Chữ, nút, menu đen trên nền sáng là MỰC, không phải brand. Không gọi Đen be, Đen kem, Đen trắng.',
    'Đen của chữ/nút nhỏ/menu/icon trên nền sáng cũng KHÔNG phải màu đi kèm → tên 1 từ (Be, Kem, Trắng, Xanh lá…). Đen chỉ là màu đi kèm khi là mảng/khối nền đen lớn. Không gọi Be đen cho trường hợp mực.',
    'Hex nền sáng hơn #EFE7DB (ví dụ #F4F1EA, #FFFFFF) → TRẮNG, kể cả khi bản đọc ghi "kem". Chỉ gọi kem/be khi nền sẫm hơn #EFE7DB.',
    'Trắng là nền mặc định: nếu design_colors có nền phụ chromatic (xanh/kem/hồng/be) → màu đi kèm là nền phụ đó, KHÔNG phải trắng. Ví dụ: nền chính trắng + nền section xanh baby → đi kèm là XANH.',
    ...(hint ? [hint] : []),
    'Chỉ dùng 2 từ khi brand màu sắc và nền khác hẳn: Vàng đen, Hồng trắng, Đỏ đen.',
    'Không dùng từ kiểu khói, plush, mist, champagne, sage, spa.',
    'Ánh xạ màu trong bản đọc → họ catalog: teal, xanh teal, lục lam, aqua, ngọc lam, cyan, xanh cổ vịt → xanh; xanh rêu, xanh moss, sage, xanh ôliu, xanh lá mạ → xanh lá; gold, vàng gold, vàng kim, vàng đồng, đồng thau → vàng; hồng pastel, hồng phấn, hồng đất, hồng nude, terracotta hồng, dusty rose, mauve, raspberry → hồng; kem, ivory, off-white, trắng ngà → kem/trắng (nền); nâu socola, nâu đất → nâu; nude, taupe suông → Nude.',
    'Tên 2 từ = [màu brand] + [màu đi kèm]. Màu đi kèm là nền HOẶC accent mạnh lặp lại khắp trang (nút, tiêu đề, logo, viền). Ví dụ chuẩn phải thuộc: nền đen + brand vàng gold → Vàng đen; brand xanh teal + accent vàng gold → Xanh vàng; nền trắng + brand hồng → Hồng trắng; nền kem + brand nâu → Nâu kem; nền đen + xanh lặp lại khắp UI → Xanh đen; section xanh nhạt + accent gold → Vàng xanh.',
    'Brand là màu chromatic LẶP LẠI mạnh nhất khắp trang (nút CTA, tiêu đề, card, icon, viền section). Màu chỉ xuất hiện ở logo mà KHÔNG lặp lại trên UI → không phải brand, chỉ là điểm nhấn logo. Ví dụ: trang toàn xanh dương, chỉ logo vàng gold → brand là XANH → Xanh đen, TUYỆT ĐỐI không gọi Vàng đen.',
    'Màu đi kèm là nền/tint chiếm ưu thế thị giác, lặp lại nhiều section nhất. Nền chính trắng nhưng các section phủ xanh nhạt/kem/hồng nhạt khắp trang → đi kèm là màu phủ đó, không phải trắng.',
    'Catalog đã có sẵn Xanh vàng, Xanh trắng, Vàng đen — ưu tiên pick đúng tên này khi khớp định nghĩa, đừng propose tên mới.',
    'Ưu tiên pick một tên có trong catalog. Chỉ propose khi catalog thật sự thiếu màu đó.',
    `Catalog hiện có: ${catalog.join(', ')}.`,
    `Bản đọc AI 1: ${JSON.stringify(visionRead)}`,
    'Trả JSON đúng key: status (pick|propose), tone (tên trong catalog khi pick, rỗng khi propose), proposed_tone (tên mới khi propose, rỗng khi pick), confidence (0..1), reason (một câu tiếng Việt).',
  ].join('\n');
}

export function validateNameToneResult(value, provider = 'groq_text', model = '', catalog = TONE_CATALOG, visionRead = null) {
  const fail = (message) => new ProviderError('NAME_TONE_INVALID_SCHEMA', message, { provider });
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw fail('AI 2 returned an invalid JSON object.');
  const confidence = Number(value.confidence);
  const reason = String(value.reason || '').trim().replace(/\s+/g, ' ').slice(0, 320);
  if (!Number.isFinite(confidence) || confidence < 0 || confidence > 1 || !reason) throw fail('AI 2 response contains an invalid field.');
  const cleaned = officialToneName(value.tone || value.proposed_tone, visionRead);
  if (catalogHas(cleaned, catalog)) {
    return { status: 'pick', tone: cleaned, proposed_tone: '', confidence: Number(confidence.toFixed(3)), reason, provider, model };
  }
  if (cleaned && REVIEW_TONE !== cleaned) {
    return { status: 'propose', tone: REVIEW_TONE, proposed_tone: cleaned, confidence: Number(confidence.toFixed(3)), reason, provider, model, needs_review: true };
  }
  return { status: 'propose', tone: REVIEW_TONE, proposed_tone: '', confidence: Number(confidence.toFixed(3)), reason: reason || 'AI 2 did not land on a catalog name.', provider, model, needs_review: true };
}
