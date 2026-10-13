/** Seed catalog: child-level color + a short color suffix. Not light/dark, not poetic. */
export const CORE_COLORS = ['đỏ', 'cam', 'vàng', 'xanh lá', 'xanh dương', 'tím', 'hồng', 'nâu', 'be', 'xám', 'trắng', 'đen'];
export const SUFFIXES = ['', 'trắng', 'đen', 'be', 'kem', 'đỏ', 'xanh lá', 'xanh dương'];
export const CHROMATIC_COLORS = ['đỏ', 'cam', 'vàng', 'xanh lá', 'xanh dương', 'tím', 'hồng', 'nâu'];
export const CANVAS_COLORS = ['be', 'kem', 'xám', 'trắng', 'đen'];
export const COLOR_ALIASES = { ivory: 'kem', cream: 'kem', champagne: 'kem' };
export const WARM_NEUTRALS = ['nâu', 'be', 'kem', 'nude', 'taupe'];
export const INK_COLORS = ['đen', 'trắng'];
export const POETIC_WORDS = ['khói', 'khoi', 'plush', 'mist', 'sage', 'spa', 'sữa', 'sua'];
export const REVIEW_TONE = 'Cần duyệt';

export function titleTone(value) {
  const text = String(value || '').trim().replace(/\s+/g, ' ');
  if (!text) return '';
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function toneTokens(value) {
  let rest = String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
  const parts = [];
  while (rest) {
    if (rest.startsWith('xanh lá')) { parts.push('xanh lá'); rest = rest.slice(7).trim(); continue; }
    if (rest.startsWith('xanh dương')) { parts.push('xanh dương'); rest = rest.slice(10).trim(); continue; }
    const [head, ...tail] = rest.split(' ');
    parts.push(head);
    rest = tail.join(' ').trim();
  }
  return parts.filter(Boolean);
}

const KNOWN_COLORS = new Set([...CORE_COLORS, ...SUFFIXES.filter(Boolean), ...WARM_NEUTRALS, ...INK_COLORS, ...Object.keys(COLOR_ALIASES), ...Object.values(COLOR_ALIASES), 'xanh']);

/**
 * Curated names the user's taxonomy uses but the core×suffix generator cannot
 * produce (bare "xanh" as brand or companion, e.g. gold accent).
 * Kept out of the ink/canvas-led exclusions: the brand always leads.
 */
export const CURATED_EXTRA_TONES = ['Xanh vàng', 'Xanh trắng', 'Xanh đen', 'Xanh kem', 'Vàng xanh', 'Xanh dương đỏ'];

/**
 * Normalize a free-form tone name: aliases, dedupe, brand-first order.
 * Warm neutrals collapse to one word only when there is no chromatic voice
 * (be/nude/kem/taupe suông → Nude/Be). A chromatic warm like nâu paired with
 * a canvas neutral is two real voices → keep both words (Nâu kem, Nâu trắng).
 */
export function simplifyToneName(value) {
  const parts = toneTokens(value).map((part) => COLOR_ALIASES[part] || part).filter((part, index, list) => {
    if (POETIC_WORDS.includes(part) || !KNOWN_COLORS.has(part)) return false;
    return part !== list[index - 1];
  });
  const hasChromatic = parts.some((part) => CHROMATIC_COLORS.includes(part));
  if (!hasChromatic && parts.length && parts.every((part) => WARM_NEUTRALS.includes(part))) {
    if (parts.includes('nude') || parts.includes('taupe')) return 'Nude';
    if (parts.includes('be')) return 'Be';
    return titleTone(parts[0]);
  }
  if (parts.length > 2) {
    const last = parts[parts.length - 1];
    const kept = CANVAS_COLORS.includes(last) ? [parts[0], last] : parts.slice(0, 2);
    return canonicalizeToneOrder(kept.join(' '));
  }
  return canonicalizeToneOrder(parts.join(' '));
}

export function isInkLedName(name) {
  const lead = toneTokens(name)[0] || '';
  return INK_COLORS.includes(lead);
}

/** AI 1 free name, without treating text ink as the page tone. */
export function visionToneHint(visionRead) {
  if (!visionRead || 'object' !== typeof visionRead) return '';
  const fromName = simplifyToneName(visionRead.tone_name);
  if (fromName && !isInkLedName(fromName)) return fromName;
  const colorBits = (Array.isArray(visionRead.design_colors) ? visionRead.design_colors : []).map((entry) => entry?.name || '').join(' ');
  const fromColors = simplifyToneName(`${visionRead.tone_name || ''} ${colorBits}`);
  if (fromColors && !isInkLedName(fromColors)) return fromColors;
  return fromName && !isInkLedName(fromName) ? fromName : '';
}

export function officialToneName(value, visionRead) {
  const cleaned = simplifyToneName(value);
  const hint = visionToneHint(visionRead);
  if (isInkLedName(cleaned) && hint) return hint;
  return cleaned;
}

/** Brand/accent first, canvas second. Đen vàng → Vàng đen. */
export function canonicalizeToneOrder(value) {
  const parts = toneTokens(value);
  if (parts.length < 2) return titleTone(value);
  if (CANVAS_COLORS.includes(parts[0]) && CHROMATIC_COLORS.includes(parts[1])) {
    const flipped = [parts[1], parts[0], ...parts.slice(2)];
    return titleTone(flipped.join(' '));
  }
  return titleTone(value);
}

export function buildToneCatalog(extra = []) {
  const names = new Set(['Nude']);
  for (const core of CORE_COLORS) {
    names.add(titleTone(core));
    for (const suffix of SUFFIXES) {
      if (!suffix || suffix === core) continue;
      if (CANVAS_COLORS.includes(core) && CHROMATIC_COLORS.includes(suffix)) continue;
      if (INK_COLORS.includes(core) && (suffix === 'be' || suffix === 'kem')) continue;
      names.add(titleTone(`${core} ${suffix}`));
    }
  }
  for (const item of extra) {
    const name = simplifyToneName(item);
    if (name && REVIEW_TONE !== name) names.add(name);
  }
  return [...names];
}

export const TONE_CATALOG = buildToneCatalog(CURATED_EXTRA_TONES);

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
