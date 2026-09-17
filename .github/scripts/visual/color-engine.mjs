export const ACCENT_FAMILIES = ['red', 'pink', 'orange', 'yellow_gold', 'brown', 'green', 'teal', 'blue', 'purple', 'neutral'];
export const CANVAS_FAMILIES = ['white', 'cream', 'gray', 'black'];

const CENTROIDS = {
  red: 25, pink: 5, orange: 55, yellow_gold: 90, brown: 65,
  green: 145, teal: 205, blue: 255, purple: 320,
};

const clamp = (value, low = 0, high = 1) => Math.max(low, Math.min(high, value));
const hex = (r, g, b) => `#${[r, g, b].map((value) => Math.round(value).toString(16).padStart(2, '0')).join('')}`.toUpperCase();

function linear(value) {
  value /= 255;
  return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
}

/** sRGB -> OKLCH. HSL is intentionally not used for classification. */
export function rgbToOklch(r, g, b) {
  const lr = linear(r), lg = linear(g), lb = linear(b);
  const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb);
  const m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb);
  const s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb);
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  const C = Math.sqrt(a * a + bb * bb);
  return { L, C, H: (Math.atan2(bb, a) * 180 / Math.PI + 360) % 360 };
}

export function familyForOklch({ L, C, H }) {
  if (C < 0.025) return L > 0.9 ? 'white' : (L > 0.72 ? 'cream' : (L < 0.28 ? 'black' : 'gray'));
  // Low-chroma warm light colours are cream/nude rather than false pink/red.
  if (L > 0.80 && C < 0.04 && H >= 25 && H <= 110) return 'cream';
  if (L < 0.34 && C < 0.09) return 'black';
  let best = 'red';
  let distance = Infinity;
  for (const [family, hue] of Object.entries(CENTROIDS)) {
    const hueDistance = Math.min(Math.abs(H - hue), 360 - Math.abs(H - hue));
    // Brown is deliberately biased for low-chroma warm hues; this avoids
    // champagne/taupe becoming red or pink.
    const adjusted = hueDistance + ('brown' === family ? Math.max(0, C - 0.16) * 90 + Math.max(0, L - 0.72) * 80 : 0);
    if (adjusted < distance) { best = family; distance = adjusted; }
  }
  if (H >= 330 || H < 20) return L >= 0.62 || C < 0.16 ? 'pink' : 'red';
  if (H >= 35 && H < 70 && (C >= 0.10 || L > 0.78)) return 'orange';
  if (H >= 45 && H < 105 && C >= 0.11) return 'yellow_gold';
  if (H >= 30 && H < 85 && C < 0.10 && L < 0.72) return 'brown';
  return best;
}

function roleEvidence(samples) {
  const scores = Object.fromEntries(ACCENT_FAMILIES.map((family) => [family, 0]));
  for (const sample of samples || []) {
    const color = parseCssRgb(sample.color);
    if (!color) continue;
    const family = familyForOklch(rgbToOklch(color.r, color.g, color.b));
    if (!(family in scores) || !ACCENT_FAMILIES.includes(family)) continue;
    const role = String(sample.role || 'unknown');
    const multiplier = { cta: 1, button: 0.8, active: 0.9, nav: 0.45, heading: 0.35, section: 0.3, card: 0.25, border: 0.12, text: 0.08, icon: 0.18 }[role] || 0.1;
    scores[family] += clamp(Number(sample.importance || sample.weight || 0)) * multiplier;
  }
  const total = Object.values(scores).reduce((sum, value) => sum + value, 0) || 1;
  return Object.fromEntries(Object.entries(scores).map(([family, value]) => [family, value / total]));
}

export async function analyzeUiColor(previewBuffer, semanticSamples = []) {
	const { default: sharp } = await import('sharp');
  const image = sharp(previewBuffer).resize({ width: 256, withoutEnlargement: true }).removeAlpha();
  const { data, info } = await image.raw().toBuffer({ resolveWithObject: true });
  const families = Object.fromEntries([...ACCENT_FAMILIES, ...CANVAS_FAMILIES].map((family) => [family, { pixels: 0, r: 0, g: 0, b: 0 }]));
  const pixels = Math.max(1, info.width * info.height);
  for (let index = 0; index < data.length; index += info.channels) {
    const r = data[index], g = data[index + 1], b = data[index + 2];
    const family = familyForOklch(rgbToOklch(r, g, b));
    const bucket = families[family] || families.neutral;
    bucket.pixels += 1; bucket.r += r; bucket.g += g; bucket.b += b;
  }
  const palette = Object.entries(families).filter(([, value]) => value.pixels).map(([family, value]) => ({
    family, hex: hex(value.r / value.pixels, value.g / value.pixels, value.b / value.pixels), coverage: Number((value.pixels / pixels).toFixed(4)), pixels: value.pixels,
  })).sort((a, b) => b.coverage - a.coverage);
  const coverage = Object.fromEntries(palette.map((entry) => [entry.family, entry.coverage]));
  const neutralCoverage = (coverage.white || 0) + (coverage.cream || 0) + (coverage.gray || 0) + (coverage.black || 0);
  const canvasCandidate = ['white', 'cream', 'gray', 'black'].sort((a, b) => (coverage[b] || 0) - (coverage[a] || 0))[0] || 'white';
  const canvasMode = (coverage.black || 0) >= 0.45 ? 'dark' : ((coverage.black || 0) >= 0.2 && neutralCoverage < 0.7 ? 'mixed' : 'light');
  const roles = roleEvidence(semanticSamples);
  const accents = ACCENT_FAMILIES.filter((family) => 'neutral' !== family).map((family) => ({
    family,
    coverage: coverage[family] || 0,
    role_score: Number((roles[family] || 0).toFixed(4)),
    score: (coverage[family] || 0) + (roles[family] || 0) * 0.38,
    hex: palette.find((entry) => entry.family === family)?.hex || '',
  })).sort((a, b) => b.score - a.score);
  const primary = accents[0] || { family: 'neutral', coverage: 0, role_score: 0, hex: '' };
  const secondary = accents.find((entry) => entry.family !== primary.family && entry.score >= primary.score * 0.22) || { family: 'neutral', coverage: 0, role_score: 0, hex: '' };
  const confidence = clamp(0.45 + Math.min(0.28, primary.score) + Math.min(0.18, primary.score - (accents[1]?.score || 0)));
  return {
    engine: 'oklch-v1', one_pixel_one_vote: true,
    canvas: { mode: canvasMode, family: canvasCandidate, coverage: Number((coverage[canvasCandidate] || 0).toFixed(4)), confidence: Number(confidence.toFixed(3)) },
    primary_accent: { family: primary.family, hex: primary.hex, coverage: Number(primary.coverage.toFixed(4)), role_score: primary.role_score },
    secondary_accent: { family: secondary.family, hex: secondary.hex, coverage: Number(secondary.coverage.toFixed(4)), role_score: secondary.role_score },
    families: coverage, palette: palette.slice(0, 12), role_evidence: roles,
    ambiguous: primary.score < 0.05 || (accents[1] && Math.abs(primary.score - accents[1].score) < 0.025),
  };
}

function parseCssRgb(value) {
  const match = String(value || '').match(/rgba?\(\s*([\d.]+)[, ]+\s*([\d.]+)[, ]+\s*([\d.]+)/i);
  return match ? { r: Number(match[1]), g: Number(match[2]), b: Number(match[3]) } : null;
}
