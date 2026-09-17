export const FAMILIES = ['white', 'ivory', 'cream', 'beige', 'greige', 'gray', 'charcoal', 'black', 'taupe', 'brown', 'terracotta', 'orange', 'peach', 'yellow', 'gold', 'champagne', 'red', 'burgundy', 'pink', 'rose', 'dusty_rose', 'purple', 'lavender', 'green', 'olive', 'sage', 'blue', 'navy', 'teal', 'aqua', 'neutral'];
export const ACCENT_FAMILIES = FAMILIES.filter((family) => !['white', 'ivory', 'cream', 'black', 'neutral'].includes(family));
export const CANVAS_FAMILIES = ['white', 'ivory', 'cream', 'beige', 'greige', 'gray', 'charcoal', 'black', 'navy', 'brown', 'other'];
const SURFACES = new Set(CANVAS_FAMILIES.filter((family) => 'other' !== family));
const clamp = (value, low = 0, high = 1) => Math.max(low, Math.min(high, value));
const hex = (r, g, b) => `#${[r, g, b].map((value) => Math.round(value).toString(16).padStart(2, '0')).join('')}`.toUpperCase();

function linear(value) { value /= 255; return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4; }
export function rgbToOklch(r, g, b) {
  const lr = linear(r), lg = linear(g), lb = linear(b);
  const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb), m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb), s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb);
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s, a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s, bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  return { L, C: Math.sqrt(a * a + bb * bb), H: (Math.atan2(bb, a) * 180 / Math.PI + 360) % 360 };
}

/** Dark is not black: measurable blue chroma remains navy. */
export function familyForOklch({ L, C, H }) {
  if (C < 0.018 && L < 0.18) return 'black';
  if (C < 0.032 && L < 0.42) return 'charcoal';
  if (C < 0.025) return L > 0.93 ? (H >= 25 && H <= 115 ? 'ivory' : 'white') : (L > 0.86 && H >= 25 && H <= 115 ? 'cream' : (L > 0.57 && L < 0.84 && H >= 42 && H <= 92 ? 'greige' : (L > 0.42 ? 'gray' : 'charcoal')));
  const warm = H >= 25 && H <= 115;
  if (H >= 20 && H < 65 && C >= 0.075) return L < 0.55 ? 'terracotta' : 'orange';
  if (L > 0.93 && C < 0.045) return warm ? 'ivory' : 'white';
  if (H >= 20 && H < 45 && L > 0.72 && C >= 0.035) return 'peach';
  if (L > 0.79 && C < 0.040 && warm) return 'cream';
  if (H >= 65 && H < 105 && L > 0.68 && C >= 0.11) return 'gold';
  if (H >= 65 && H < 105 && L > 0.68 && C >= 0.060) return 'champagne';
  if (L > 0.57 && C < 0.038 && H >= 42 && H <= 92) return 'greige';
  if (L > 0.62 && C < 0.105 && H >= 42 && H <= 100) return 'beige';
  if (L >= 0.50 && L <= 0.62 && C < 0.070 && H >= 35 && H <= 95) return 'brown';
  if (L < 0.50 && C < 0.070 && H >= 35 && H <= 95) return 'taupe';
  if (L < 0.50 && C >= 0.040 && H >= 220 && H <= 275) return 'navy';
  if (L < 0.46 && C >= 0.045 && (H >= 345 || H < 20)) return 'burgundy';
  if (H >= 195 && H < 225) return L > 0.62 ? 'aqua' : 'teal';
  if (H >= 225 && H < 285) return L < 0.50 ? 'navy' : 'blue';
  if (H >= 78 && H < 105 && C >= 0.11) return 'gold';
  if (H >= 105 && H < 112 && C >= 0.11) return 'yellow';
  if (H >= 125 && H < 170) return L < 0.55 && C < 0.10 ? 'sage' : 'green';
  if (H >= 80 && H < 125) return L < 0.60 && C < 0.11 ? 'olive' : 'green';
  if (H >= 285 && H < 335) return L > 0.68 && C < 0.11 ? 'lavender' : 'purple';
  if ((H >= 335 || H < 20) && L > 0.56 && L < 0.75 && C < 0.12) return 'dusty_rose';
  if ((H >= 335 || H < 20) && L >= 0.62) return 'pink';
  if ((H >= 335 || H < 20) && L < 0.56) return 'red';
  if (H >= 20 && H < 65 && C >= 0.075) return L < 0.55 ? 'terracotta' : 'orange';
  if (H >= 20 && H < 45) return L > 0.66 && C < 0.12 ? 'peach' : (L < 0.55 ? 'terracotta' : 'orange');
  if (H >= 45 && H < 78) return L > 0.70 && C < 0.10 ? 'champagne' : (C >= 0.12 ? 'gold' : 'brown');
  if (H >= 78 && H < 112) return C >= 0.11 ? 'yellow' : 'gold';
  if (H >= 20 && H < 90) return L < 0.63 ? 'brown' : 'beige';
  return 'neutral';
}

function parseCssRgb(value) { const match = String(value || '').match(/rgba?\(\s*([\d.]+)[, ]+\s*([\d.]+)[, ]+\s*([\d.]+)/i); return match ? { r: Number(match[1]), g: Number(match[2]), b: Number(match[3]) } : null; }
function stats() { return Object.fromEntries(FAMILIES.map((family) => [family, { weight: 0, r: 0, g: 0, b: 0, light: 0, dark: 0, mid: 0 }])); }
function add(bucket, color, weight) { if (!color || !Number.isFinite(weight) || weight <= 0) return; const perceptual = rgbToOklch(color.r, color.g, color.b), entry = bucket[familyForOklch(perceptual)] || bucket.neutral; entry.weight += weight; entry.r += color.r * weight; entry.g += color.g * weight; entry.b += color.b * weight; if (perceptual.L >= 0.70) entry.light += weight; else if (perceptual.L <= 0.38) entry.dark += weight; else entry.mid += weight; }
function normalized(bucket) { const total = Object.values(bucket).reduce((sum, entry) => sum + entry.weight, 0) || 1; return Object.fromEntries(Object.entries(bucket).map(([family, entry]) => [family, { ...entry, coverage: entry.weight / total }])); }
function evidence(samples, structural = false) { const result = stats(), multipliers = { cta: 1.3, button: 1.15, active: 1.2, nav: 0.8, section: 0.55, card: 0.45, control: 0.7, heading: 0.25, icon: 0.16, border: 0.12, text: 0.06, canvas: 0.45 }; for (const sample of samples || []) { if (structural && ('background' !== sample.kind || !(sample.structural || ['canvas', 'section', 'nav', 'card'].includes(sample.role)))) continue; add(result, parseCssRgb(sample.color), Number(sample.weight || 0) * (structural ? (sample.structural ? 1.35 : 0.75) : (multipliers[sample.role] || 0.1))); } return normalized(result); }
function surfaceEntry(family, source) { const row = source[family]; return { family, coverage: row?.coverage || 0, hex: row?.weight ? hex(row.r / row.weight, row.g / row.weight, row.b / row.weight) : '' }; }
function canvasFrom(structural, fallback) { const source = Object.values(structural).some((entry) => entry.coverage > 0.01) ? structural : fallback, candidates = [...SURFACES].map((family) => surfaceEntry(family, source)).sort((a, b) => b.coverage - a.coverage), primary = candidates[0]?.coverage ? candidates[0] : { family: 'white', coverage: 1, hex: '#FFFFFF' }, secondary = candidates.find((entry) => entry.family !== primary.family && entry.coverage >= 0.06) || { family: '', coverage: 0, hex: '' }, light = Object.values(source).reduce((sum, entry) => sum + entry.light, 0), dark = Object.values(source).reduce((sum, entry) => sum + entry.dark, 0), mid = Object.values(source).reduce((sum, entry) => sum + entry.mid, 0), total = light + dark + mid || 1, lightRatio = light / total, darkRatio = dark / total, midRatio = mid / total; return { mode: lightRatio >= 0.60 ? 'light' : (darkRatio >= 0.60 ? 'dark' : 'mixed'), family: primary.family, primary_surface: primary.family, secondary_surface: secondary.family, light_surface_ratio: Number(lightRatio.toFixed(4)), dark_surface_ratio: Number(darkRatio.toFixed(4)), mid_surface_ratio: Number(midRatio.toFixed(4)), coverage: Number(primary.coverage.toFixed(4)), primary_hex: primary.hex, secondary_hex: secondary.hex }; }

/** Pure canvas fixture helper: validates the structural-surface model without image tooling. */
export function canvasFromFamilyCoverage(coverage = {}) {
  const bucket = stats(), light = new Set(['white', 'ivory', 'cream', 'beige', 'greige', 'gray']), dark = new Set(['black', 'charcoal', 'navy', 'brown']);
  for (const [family, amount] of Object.entries(coverage)) { const entry = bucket[family]; if (!entry || !Number.isFinite(amount) || amount <= 0) continue; entry.weight = amount; if (light.has(family)) entry.light = amount; else if (dark.has(family)) entry.dark = amount; else entry.mid = amount; }
  const normalizedBucket = normalized(bucket); return canvasFrom(normalizedBucket, normalizedBucket);
}

/** Structural DOM surfaces lead; media-heavy screenshot pixels are fallback evidence only. */
export async function analyzeUiColor(previewBuffer, semanticSamples = []) {
  const { default: sharp } = await import('sharp'); const image = sharp(previewBuffer).resize({ width: 256, withoutEnlargement: true }).removeAlpha(), { data, info } = await image.raw().toBuffer({ resolveWithObject: true }), pixels = stats();
  for (let index = 0; index < data.length; index += info.channels) add(pixels, { r: data[index], g: data[index + 1], b: data[index + 2] }, 1);
  const coverageRows = normalized(pixels), structural = evidence(semanticSamples, true), roles = evidence(semanticSamples), canvas = canvasFrom(structural, coverageRows), palette = Object.entries(coverageRows).filter(([, entry]) => entry.coverage > 0).map(([family, entry]) => ({ family, hex: hex(entry.r / entry.weight, entry.g / entry.weight, entry.b / entry.weight), coverage: Number(entry.coverage.toFixed(4)), pixels: Math.round(entry.weight) })).sort((a, b) => b.coverage - a.coverage);
  const accents = ACCENT_FAMILIES.map((family) => ({ family, coverage: coverageRows[family]?.coverage || 0, role_score: roles[family]?.coverage || 0, structural_score: structural[family]?.coverage || 0, hex: palette.find((entry) => entry.family === family)?.hex || '' })).map((entry) => ({ ...entry, score: entry.role_score * 0.62 + entry.structural_score * 0.33 + entry.coverage * 0.16 })).sort((a, b) => b.score - a.score);
  let primary = accents[0] || { family: 'neutral', score: 0, hex: '' }; const neutralStructural = ['gray', 'greige', 'beige', 'taupe', 'brown', 'charcoal'].map((family) => ({ family, score: structural[family]?.coverage || 0, hex: surfaceEntry(family, structural).hex })).sort((a, b) => b.score - a.score)[0]; if (neutralStructural?.score >= primary.score * 1.15 && neutralStructural.score >= 0.12) primary = neutralStructural;
  const secondary = accents.find((entry) => entry.family !== primary.family && entry.score >= primary.score * 0.24) || { family: canvas.primary_surface, score: 0, hex: canvas.primary_hex }, confidence = clamp(0.45 + Math.min(0.30, primary.score) + Math.min(0.18, Math.max(0, primary.score - (accents[1]?.score || 0))));
  return { engine: 'oklch-v3.15', one_pixel_one_vote: true, canvas: { ...canvas, confidence: Number(confidence.toFixed(3)) }, primary_accent: { family: primary.family, hex: primary.hex, coverage: Number((primary.coverage || 0).toFixed(4)), role_score: Number((primary.role_score || 0).toFixed(4)), score: Number((primary.score || 0).toFixed(4)) }, secondary_accent: { family: secondary.family, hex: secondary.hex, coverage: Number((secondary.coverage || 0).toFixed(4)), role_score: Number((secondary.role_score || 0).toFixed(4)) }, families: Object.fromEntries(Object.entries(coverageRows).map(([family, row]) => [family, Number(row.coverage.toFixed(4))])), palette: palette.slice(0, 20), role_evidence: Object.fromEntries(Object.entries(roles).map(([family, row]) => [family, Number(row.coverage.toFixed(4))])), structural_evidence: Object.fromEntries(Object.entries(structural).map(([family, row]) => [family, Number(row.coverage.toFixed(4))])), ambiguous: primary.score < 0.035 || (accents[1] && Math.abs(primary.score - accents[1].score) < 0.018) };
}
