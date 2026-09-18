const CHROMATIC_KEYS = ['red', 'pink', 'brown', 'yellow', 'green', 'blue', 'purple'];
const ALL_KEYS = [...CHROMATIC_KEYS, 'dark', 'light', 'cream', 'neutral'];

function clamp(value, min = 0, max = 1) {
  return Math.max(min, Math.min(max, Number(value) || 0));
}

function parseRgbParts(value) {
  const text = String(value || '').trim();
  const rgb = text.match(/rgba?\(\s*([\d.]+)[, ]+\s*([\d.]+)[, ]+\s*([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)/i);
  if (rgb) {
    const alpha = rgb[4] == null ? 1 : (String(rgb[4]).endsWith('%') ? Number.parseFloat(rgb[4]) / 100 : Number(rgb[4]));
    return { r: Number(rgb[1]), g: Number(rgb[2]), b: Number(rgb[3]), a: clamp(alpha) };
  }
  const hex = text.match(/^#([\da-f]{3,8})$/i);
  if (hex) {
    const raw = hex[1];
    const expanded = raw.length <= 4 ? raw.split('').map((value) => value + value).join('') : raw;
    const alpha = expanded.length === 8 ? Number.parseInt(expanded.slice(6), 16) / 255 : 1;
    return { r: Number.parseInt(expanded.slice(0, 2), 16), g: Number.parseInt(expanded.slice(2, 4), 16), b: Number.parseInt(expanded.slice(4, 6), 16), a: clamp(alpha) };
  }
  return null;
}

function parseRgb(value) {
  const rgb = parseRgbParts(value);
  return rgb ? { r: rgb.r, g: rgb.g, b: rgb.b } : null;
}

function rgbToHsl({ r, g, b }) {
  r = clamp(r / 255); g = clamp(g / 255); b = clamp(b / 255);
  const max = Math.max(r, g, b), min = Math.min(r, g, b), delta = max - min;
  let h = 0;
  if (delta) h = 60 * (((max === r ? (g - b) / delta + 6 : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4)) % 6);
  const l = (max + min) / 2;
  const s = delta ? delta / (1 - Math.abs(2 * l - 1)) : 0;
  return { h, s, l };
}

function category(rgb) {
  const { h, s, l } = rgbToHsl(rgb);
  if (l <= 0.22) return 'dark';
  if (s <= 0.12) return l >= 0.72 ? 'light' : (l <= 0.38 ? 'dark' : 'neutral');
  if (l >= 0.82 && s <= 0.38 && (h < 70 || h >= 330)) return 'cream';
  // True red must be saturated and materially darker than a pale rose.
  // This prevents pink/beige UI from becoming red because of a photo sample.
  if ((h < 10 || h >= 350) && l < 0.60 && s >= 0.42 && rgb.r > rgb.g * 1.22 && rgb.r > rgb.b * 1.22) return 'red';
  if (h >= 315 || h < 14) return 'pink';
  if (h >= 255 && h < 315) return 'purple';
  if (h >= 165 && h < 255) return 'blue';
  if (h >= 72 && h < 165) return 'green';
  if (h >= 38 && h < 72) return 'yellow';
  if (h >= 14 && h < 38) return l < 0.76 ? 'brown' : 'cream';
  return l >= 0.72 ? 'light' : 'neutral';
}

function luminance({ r, g, b }) {
  const channel = (value) => {
    const normalized = clamp(value / 255);
    return normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function hex(rgb) {
  return `#${[rgb.r, rgb.g, rgb.b].map((value) => Math.round(clamp(value / 255) * 255).toString(16).padStart(2, '0')).join('')}`.toUpperCase();
}

function primaryFamily(coverage) {
  const chroma = CHROMATIC_KEYS.reduce((sum, key) => sum + coverage[key], 0);
  return chroma >= 0.02 ? CHROMATIC_KEYS.reduce((best, key) => coverage[key] > coverage[best] ? key : best, CHROMATIC_KEYS[0]) : 'neutral';
}

function pairFamilies(coverage, surface) {
  const primary = primaryFamily(coverage);
  const dark = coverage.dark;
  const light = coverage.light + coverage.cream;
  const secondary = 'dark' === surface || dark >= 0.22 ? 'black' : (coverage.yellow >= 0.08 && coverage.yellow >= coverage[primary] * 0.22 ? 'yellow' : (light >= 0.28 ? 'white' : (coverage.cream >= 0.12 ? 'cream' : 'neutral')));
  return { primary, secondary, surface };
}

function classifyCandidate(coverage, total, averageSaturation) {
  const chroma = CHROMATIC_KEYS.reduce((sum, key) => sum + coverage[key], 0);
  const mix = Object.fromEntries(CHROMATIC_KEYS.map((key) => [key, chroma ? coverage[key] / chroma : 0]));
  const dark = coverage.dark;
  const light = coverage.light + coverage.cream;
  const cream = coverage.cream;
  const greenBlue = coverage.green + coverage.blue;
  let tone = 'Cần duyệt';
  if (total < 0.00001 || averageSaturation < 0.035) {
    tone = dark >= 0.56 ? 'Đen trắng' : (cream >= 0.23 ? 'Trắng kem' : tone);
  } else if (dark >= 0.50 && coverage.yellow >= 0.035) tone = 'Đen vàng';
  else if (dark >= 0.50 && greenBlue >= 0.045) tone = 'Xanh đen';
  else if (coverage.yellow >= 0.06 && dark >= 0.38) tone = 'Vàng đen';
  else if (coverage.pink >= 0.06 && dark >= 0.32) tone = 'Hồng đen';
  else if (coverage.pink >= 0.045 && coverage.green >= 0.028) tone = 'Hồng xanh trắng';
  else if (coverage.brown >= 0.065 && mix.brown >= 0.30) tone = dark >= 0.34 ? 'Nâu đen' : (coverage.yellow >= 0.035 ? 'Nâu vàng' : (cream >= 0.20 && coverage.light < 0.20 ? 'Nâu kem' : (light >= 0.44 ? 'Nâu trắng' : 'Nâu kem')));
  else if (coverage.red >= 0.05 && mix.red >= 0.38) tone = dark >= 0.32 ? 'Đỏ đen' : 'Đỏ trắng';
  else if (coverage.pink >= 0.04 && mix.pink >= 0.30) tone = 'Hồng trắng';
  else if (coverage.red >= 0.025 && coverage.pink >= 0.03) tone = 'Đỏ hồng';
  else if (coverage.purple >= 0.045 && dark >= 0.34) tone = 'Tím đen';
  else if (coverage.purple >= 0.045 && light >= 0.45) tone = 'Tím trắng';
  else if (coverage.purple >= 0.035 && coverage.pink >= 0.025) tone = 'Tím hồng';
  else if (greenBlue >= 0.05 && coverage.yellow >= 0.025) tone = 'Xanh vàng';
  else if (greenBlue >= 0.06) tone = cream >= 0.20 ? 'Xanh kem' : (light >= 0.34 ? 'Xanh trắng' : 'Xanh đen');
  else if (coverage.yellow >= 0.055) tone = light >= 0.42 ? 'Vàng trắng' : 'Vàng kem sáng';
  else if (cream >= 0.28) tone = 'Trắng kem';
  return { tone, chroma, mix };
}

function candidateConfidence(tone, coverage, chroma, averageSaturation) {
  if ('Cần duyệt' === tone) return 0.35;
  const relevant = {
    'Đỏ': coverage.red, 'Hồng': coverage.pink, 'Nâu': coverage.brown, 'Vàng': coverage.yellow,
    'Xanh': coverage.green + coverage.blue, 'Tím': coverage.purple,
  };
  const primary = Object.entries(relevant).find(([label]) => tone.startsWith(label))?.[1] || chroma;
  const sorted = Object.values(relevant).sort((a, b) => b - a);
  const margin = Math.max(0, (sorted[0] || 0) - (sorted[1] || 0));
  return Number(clamp(0.48 + Math.min(0.28, primary * 0.9) + Math.min(0.16, margin * 0.7) + Math.min(0.08, averageSaturation * 0.16)).toFixed(2));
}

export function summarizeUiSamples(samples) {
  const buckets = Object.fromEntries(ALL_KEYS.map((key) => [key, 0]));
  const colors = new Map();
  let total = 0;
  let weightedSaturation = 0;
  let weightedLuminance = 0;
  let weightedLuminanceSquared = 0;
  let structuralWeight = 0;
  let surfaceWeight = 0;
  for (const sample of samples || []) {
    const rgb = parseRgb(sample.color);
    const weight = Number(sample.weight || sample.area_weight || 0);
    if (!rgb || !Number.isFinite(weight) || weight <= 0) continue;
    const alpha = clamp(sample.opacity == null ? 1 : sample.opacity);
    const effectiveWeight = weight * alpha;
    if (effectiveWeight <= 0) continue;
    const key = category(rgb);
    const hsl = rgbToHsl(rgb);
    const lum = luminance(rgb);
    buckets[key] += effectiveWeight;
    total += effectiveWeight;
    weightedSaturation += hsl.s * effectiveWeight;
    weightedLuminance += lum * effectiveWeight;
    weightedLuminanceSquared += lum * lum * effectiveWeight;
    const colorKey = hex(rgb);
    const current = colors.get(colorKey) || { weight: 0, category: key, role: sample.role || sample.kind || 'ui' };
    current.weight += effectiveWeight;
    colors.set(colorKey, current);
    if ('background' === sample.kind || 'surface' === sample.role || sample.structural) structuralWeight += effectiveWeight;
    if ('background' === sample.kind || 'border' === sample.kind || 'button' === sample.role) surfaceWeight += effectiveWeight;
  }
  const share = (key) => total ? buckets[key] / total : 0;
  const coverage = Object.fromEntries(ALL_KEYS.map((key) => [key, share(key)]));
  const averageSaturation = total ? weightedSaturation / total : 0;
  const averageLuminance = total ? weightedLuminance / total : 0;
  const variance = total ? Math.max(0, weightedLuminanceSquared / total - averageLuminance ** 2) : 0;
  const chromaticTotal = CHROMATIC_KEYS.reduce((sum, key) => sum + buckets[key], 0);
  const surface = coverage.dark >= 0.38 || averageLuminance < 0.32 ? 'dark' : 'light';
  const candidate = classifyCandidate(coverage, total, averageSaturation);
  const confidence = candidateConfidence(candidate.tone, coverage, candidate.chroma, averageSaturation);
  const dominantColors = [...colors.entries()]
    .sort(([, a], [, b]) => b.weight - a.weight)
    .slice(0, 8)
    .map(([color, value]) => ({ color, category: value.category, coverage: Number((total ? value.weight / total : 0).toFixed(4)), role: value.role }));
  const warm = coverage.red + coverage.pink + coverage.brown + coverage.yellow;
  const cool = coverage.green + coverage.blue + coverage.purple;
  const warmCool = warm - cool;
  const warmCoolTendency = warmCool > 0.10 ? 'warm' : (warmCool < -0.10 ? 'cool' : 'balanced');
  const contrastLevel = variance >= 0.075 ? 'high' : (variance >= 0.028 ? 'medium' : 'low');
  const pair = pairFamilies(coverage, surface);
  const percent = (value) => Math.round(clamp(value) * 100);
  const text = `Deterministic UI evidence (visible area weighted; photos/media excluded): ${surface} surface; light/cream ${percent(coverage.light + coverage.cream)}%, dark ${percent(coverage.dark)}%; red ${percent(coverage.red)}%, pink ${percent(coverage.pink)}%, brown ${percent(coverage.brown)}%, yellow ${percent(coverage.yellow)}%, green ${percent(coverage.green)}%, blue ${percent(coverage.blue)}%, purple ${percent(coverage.purple)}%. Candidate: ${candidate.tone} (${Math.round(confidence * 100)}%); warm/cool ${warmCoolTendency}; contrast ${contrastLevel}.`;
  return {
    metrics_version: 5,
    scope: 'legacy_diagnostic_only',
    weighting: 'visible_area',
    legacy_candidate: candidate.tone,
    legacy_candidate_confidence: confidence,
    legacy_primary_family: pair.primary,
    legacy_secondary_family: pair.secondary,
    surface,
    coverage,
    // Keep these top-level keys for the existing API, but make them direct
    // visible-UI-area shares (not shares normalized only against chroma).
    ...Object.fromEntries(CHROMATIC_KEYS.map((key) => [key, coverage[key]])),
    dark: coverage.dark,
    light: coverage.light + coverage.cream,
    cream: coverage.cream,
    chroma_ratio: total ? chromaticTotal / total : 0,
    average_saturation: averageSaturation,
    average_luminance: averageLuminance,
    warm_cool_tendency: warmCoolTendency,
    warm_ratio: warm,
    cool_ratio: cool,
    contrast_level: contrastLevel,
    structural_weight: total ? structuralWeight / total : 0,
    surface_weight: total ? surfaceWeight / total : 0,
    sample_count: (samples || []).length,
    weighted_sample_count: colors.size,
    top_colors: dominantColors.map((value) => value.color),
    dominant_structural_colors: dominantColors,
    text,
  };
}

export { category, parseRgb, rgbToHsl };
