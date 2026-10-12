/** Seed catalog: child-level color + a short color suffix. Not light/dark, not poetic. */
export const CORE_COLORS = ['đỏ', 'cam', 'vàng', 'xanh lá', 'xanh dương', 'tím', 'hồng', 'nâu', 'be', 'xám', 'trắng', 'đen'];
export const SUFFIXES = ['', 'trắng', 'đen', 'be', 'đỏ', 'xanh lá', 'xanh dương'];
export const REVIEW_TONE = 'Cần duyệt';

export function titleTone(value) {
  const text = String(value || '').trim().replace(/\s+/g, ' ');
  if (!text) return '';
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function buildToneCatalog(extra = []) {
  const names = new Set();
  for (const core of CORE_COLORS) {
    names.add(titleTone(core));
    for (const suffix of SUFFIXES) {
      if (!suffix || suffix === core) continue;
      names.add(titleTone(`${core} ${suffix}`));
    }
  }
  for (const item of extra) {
    const name = titleTone(item);
    if (name && REVIEW_TONE !== name) names.add(name);
  }
  return [...names];
}

export const TONE_CATALOG = buildToneCatalog();

export function catalogHas(tone, catalog = TONE_CATALOG) {
  const name = titleTone(tone);
  return REVIEW_TONE !== name && (catalog || TONE_CATALOG).includes(name);
}

export function familyKey(tone) {
  const name = titleTone(tone);
  if (name.startsWith('Xanh lá')) return 'Xanh lá';
  if (name.startsWith('Xanh dương')) return 'Xanh dương';
  return name.split(' ')[0] || '';
}
