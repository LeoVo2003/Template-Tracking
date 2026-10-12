import { ProviderError } from './providers/common.mjs';

export const DIRECT_VISION_MODES = ['legacy', 'benchmark_only', 'direct_vision'];
export const TEMPERATURES = ['ấm', 'lạnh', 'trung tính'];
export const CONTRASTS = ['cao', 'vừa', 'thấp'];
export const DIRECT_VISION_VERSION = 'tone-mau-ai1-v1';

export function normalizeHex(value) {
  const match = String(value ?? '').trim().match(/^#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/);
  if (!match) return '';
  const digits = 3 === match[1].length ? match[1].split('').map((char) => char + char).join('') : match[1];
  return `#${digits.toUpperCase()}`;
}

function cleanText(value, max = 160) {
  return String(value || '').trim().replace(/\s+/g, ' ').slice(0, max);
}

/** Matches tone-mau.html: free designer read, no catalog name. */
export const DIRECT_VISION_SCHEMA = {
  type: 'object',
  properties: {
    tone_name: { type: 'string' },
    one_liner: { type: 'string' },
    temperature: { type: 'string', enum: TEMPERATURES },
    moods: { type: 'array', items: { type: 'string' } },
    fits: { type: 'string' },
    design_colors: { type: 'array', items: { type: 'object' } },
    accent: { type: 'object' },
    photo_colors_ignored: { type: 'array', items: { type: 'string' } },
    contrast: { type: 'object' },
    cohesion: { type: 'object' },
    watchouts: { type: 'array', items: { type: 'string' } },
  },
  required: ['tone_name', 'one_liner', 'temperature', 'moods', 'fits', 'design_colors', 'accent', 'photo_colors_ignored', 'contrast', 'cohesion', 'watchouts'],
  additionalProperties: true,
};

export function directVisionPrompt() {
  return [
    'Ảnh là screenshot homepage.',
    'Hãy nheo mắt nhìn CẢ TRANG trong khoảng hai giây, rồi nói trang đang mang tone gì.',
    'Đây là cảm nhận của designer, không phải thống kê pixel. Đừng liệt kê mọi màu xuất hiện. Chỉ giữ những màu tạo nên ấn tượng đầu tiên.',
    'Nền trắng chiếm nhiều diện tích chưa chắc là tone. Ảnh hero (da, hoa, sản phẩm) chỉ tính khi nó quyết định cảm giác cả trang.',
    'Bỏ qua thanh trình duyệt, banner cookie, và màu icon mạng xã hội.',
    'Logo chỉ là bằng chứng phụ: màu logo không phải brand trừ khi nó lặp lại trên nút, menu, tiêu đề hoặc UI.',
    'Trả về JSON với đúng các key: tone_name (string, tiếng Việt, ngắn), one_liner (1 câu tiếng Việt), temperature (ấm|lạnh|trung tính), moods (mảng 3 từ tiếng Việt), fits (trang hợp kiểu tiệm nào), design_colors (mảng 2 đến 4 object: role, name, hex, why; hex dạng #RRGGBB, màu gần để hình dung), accent (object: name, hex, used_for), photo_colors_ignored (mảng string), contrast (object: level cao/vừa/thấp, note), cohesion (object: score 1-10, note), watchouts (mảng string, có thể rỗng).',
    'Không chọn tên từ catalog. Không ép vào vàng đen / hồng trắng có sẵn. Chỉ đọc.',
  ].join(' ');
}

export function directVisionJudgePrompt({ previousResult = null } = {}) {
  return `${directVisionPrompt()}\nBản đọc trước: ${JSON.stringify(previousResult || {})}\nĐọc lại độc lập từ screenshot. Chỉ trả JSON.`;
}

function colorEntry(entry = {}) {
  const hex = normalizeHex(entry.hex);
  if (!hex) return null;
  return {
    role: cleanText(entry.role, 32),
    name: cleanText(entry.name, 40),
    hex,
    why: cleanText(entry.why, 160),
  };
}

export function validateDirectVisionResult(value, provider = 'direct_vision', model = '') {
  const fail = (message) => new ProviderError('DIRECT_VISION_INVALID_SCHEMA', message, { provider });
  if (!value || 'object' !== typeof value || Array.isArray(value)) throw fail('AI 1 returned an invalid JSON object.');
  const toneName = cleanText(value.tone_name, 60);
  const oneLiner = cleanText(value.one_liner, 220);
  if (!toneName || !oneLiner || !TEMPERATURES.includes(value.temperature)) throw fail('AI 1 missed tone_name, one_liner, or temperature.');
  const moods = (Array.isArray(value.moods) ? value.moods : []).map((item) => cleanText(item, 24)).filter(Boolean).slice(0, 3);
  const colors = (Array.isArray(value.design_colors) ? value.design_colors : []).map(colorEntry).filter(Boolean).slice(0, 4);
  if (colors.length < 2) throw fail('AI 1 must return at least two design colors.');
  const accent = colorEntry(value.accent) || { name: cleanText(value.accent?.name, 40), hex: '', used_for: cleanText(value.accent?.used_for, 80) };
  if (accent) accent.used_for = cleanText(value.accent?.used_for, 80);
  const contrastLevel = CONTRASTS.includes(value.contrast?.level) ? value.contrast.level : 'vừa';
  const cohesionScore = Math.max(1, Math.min(10, Number(value.cohesion?.score) || 5));
  const visionRead = {
    tone_name: toneName,
    one_liner: oneLiner,
    temperature: value.temperature,
    moods,
    fits: cleanText(value.fits, 80),
    design_colors: colors,
    accent,
    photo_colors_ignored: (Array.isArray(value.photo_colors_ignored) ? value.photo_colors_ignored : []).map((item) => cleanText(item, 40)).filter(Boolean).slice(0, 8),
    contrast: { level: contrastLevel, note: cleanText(value.contrast?.note, 160) },
    cohesion: { score: cohesionScore, note: cleanText(value.cohesion?.note, 160) },
    watchouts: (Array.isArray(value.watchouts) ? value.watchouts : []).map((item) => cleanText(item, 80)).filter(Boolean).slice(0, 6),
  };
  return {
    vision_read: visionRead,
    descriptor: toneName,
    temperature: visionRead.temperature,
    confidence: Number((cohesionScore / 10).toFixed(3)),
    reason: oneLiner,
    palette: {
      canvas: colors[0] || null,
      brand: colors[1] || accent || null,
      support: colors[2] || null,
    },
    provider,
    model,
  };
}

export function extractPriorVisionRead(raw) {
  const source = 'string' === typeof raw ? (() => { try { return JSON.parse(raw); } catch { return null; } })() : raw;
  const read = source?.result?.vision_read || source?.vision_read || null;
  if (!read || !read.tone_name || !Array.isArray(read.design_colors) || read.design_colors.length < 2) return null;
  return read;
}

export function directVisionEligible(item = {}) {
  return Boolean(item.screenshot_url) && !item.diagnostic_screenshot_url && !['HTTP_401', 'HTTP_403', 'CF_CHALLENGE', 'CAPTCHA', 'MAINTENANCE'].includes(String(item.last_error_code || '').toUpperCase());
}
