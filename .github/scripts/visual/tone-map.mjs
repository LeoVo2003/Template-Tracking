const surfaceWord = { white: 'trắng', ivory: 'trắng', cream: 'kem', beige: 'be', greige: 'be', gray: 'xám', charcoal: 'xám', black: 'đen', navy: 'đen', brown: 'nâu' };
const primaryWord = { white: 'Trắng', ivory: 'Trắng', cream: 'Kem', beige: 'Be', greige: 'Greige', gray: 'Xám', charcoal: 'Charcoal', black: 'Đen', taupe: 'Nâu', brown: 'Nâu', terracotta: 'Cam đất', orange: 'Cam', peach: 'Đào', yellow: 'Vàng', gold: 'Vàng', champagne: 'Vàng', red: 'Đỏ', burgundy: 'Đỏ rượu', pink: 'Hồng', rose: 'Rose', dusty_rose: 'Hồng đất', purple: 'Tím', lavender: 'Lavender', green: 'Xanh lá', olive: 'Olive', sage: 'Sage', blue: 'Xanh dương', navy: 'Navy', teal: 'Teal', aqua: 'Xanh ngọc' };
const blueFamilies = new Set(['blue', 'navy', 'teal', 'aqua']);
const greenFamilies = new Set(['green', 'sage', 'olive']);
const neutralFamilies = new Set(['white', 'ivory', 'cream', 'beige', 'greige', 'gray', 'charcoal', 'black', 'taupe']);

function canvasSurface(result) {
  const canvas = result.canvas || {}, primary = result.primary_surface || canvas.primary_surface || result.canvas_family || canvas.family || 'white', secondary = result.secondary_surface || canvas.secondary_surface || '';
  const dark = Number(result.dark_surface_ratio ?? canvas.dark_surface_ratio ?? 0), light = Number(result.light_surface_ratio ?? canvas.light_surface_ratio ?? 0), mode = result.canvas_mode || canvas.mode || 'light';
  if ('dark' === mode || (dark >= 0.60 && light <= 0.25)) return ['black', 'charcoal', 'navy'].includes(primary) ? primary : 'black';
  // Mixed pages retain their light base; a navy section is structural context, not a black base.
  if ('mixed' === mode && ['white', 'ivory', 'cream', 'beige', 'greige', 'gray'].includes(primary)) return primary;
  return primary || secondary || 'white';
}
function normalizedGroup(primary, base, secondary) {
  const baseWord = surfaceWord[base] || 'trắng';
  if ('black' === primary) return 'đen' === baseWord ? 'Đen xám' : 'Đen trắng';
  if ('charcoal' === primary) return 'đen' === baseWord ? 'Đen xám' : 'Đen trắng';
  if ('gray' === primary || 'greige' === primary) return `Xám ${'xám' === baseWord ? 'trắng' : baseWord}`;
  if ('beige' === primary) return `Be ${'be' === baseWord ? 'kem' : baseWord}`;
  if ('cream' === primary) return `Kem ${'kem' === baseWord ? 'trắng' : baseWord}`;
  if ('ivory' === primary || 'white' === primary) return `Trắng ${'trắng' === baseWord ? 'kem' : baseWord}`;
  if ('taupe' === primary) return `Nâu ${baseWord}`;
  if ('brown' === primary) return `Nâu ${baseWord}`;
  if (['yellow', 'gold', 'champagne'].includes(primary)) return `Vàng ${'xám' === baseWord ? 'trắng' : baseWord}`;
  if (['orange', 'peach', 'terracotta'].includes(primary)) return `Cam ${'xám' === baseWord ? 'trắng' : baseWord}`;
  if (['red', 'burgundy'].includes(primary)) return 'pink' === secondary ? 'Đỏ hồng' : `Đỏ ${'xám' === baseWord ? 'trắng' : baseWord}`;
  if (['pink', 'rose', 'dusty_rose'].includes(primary)) return blueFamilies.has(secondary) ? 'Hồng xanh trắng' : `Hồng ${baseWord}`;
  if (['purple', 'lavender'].includes(primary)) return 'pink' === secondary ? 'Tím hồng' : `Tím ${baseWord}`;
  if (greenFamilies.has(primary)) return 'đen' === baseWord ? 'Xanh đen' : ('kem' === baseWord ? 'Xanh kem' : 'Xanh trắng');
  if (blueFamilies.has(primary)) return 'đen' === baseWord ? 'Xanh đen' : ('kem' === baseWord ? 'Xanh kem' : ('be' === baseWord ? 'Xanh trắng' : 'Xanh trắng'));
  return 'Cần duyệt';
}

/** Semantic result -> exact color system -> compact user-facing tone group. */
export function resolveTone(result = {}) {
  const primary = result.primary_family || 'neutral', secondary = result.secondary_family || '', base = canvasSurface(result);
  if (result.needs_review || ('neutral' === primary && !neutralFamilies.has(base))) return { precise_tone: 'Cần duyệt', tone_group: 'Cần duyệt', base_surface: base };
  const effectivePrimary = 'neutral' === primary ? base : primary, word = primaryWord[effectivePrimary];
  if (!word) return { precise_tone: 'Cần duyệt', tone_group: 'Cần duyệt', base_surface: base };
  const precise_tone = `${word} ${surfaceWord[base] || 'trắng'}`;
  return { precise_tone, tone_group: normalizedGroup(effectivePrimary, base, secondary), base_surface: base };
}

/** Legacy callers persist the group in `tone`; V3.15 additionally persists precise_tone. */
export function mapVietnameseTone(result) { return resolveTone(result).tone_group; }
