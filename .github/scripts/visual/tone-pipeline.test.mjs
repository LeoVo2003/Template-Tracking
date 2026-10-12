import { evaluateDirectVisionConflict } from './classify.mjs';
import { buildToneCatalog, canonicalizeToneOrder, catalogHas, REVIEW_TONE, TONE_CATALOG, simplifyToneName, titleTone } from './tone-catalog.mjs';
import { validateDirectVisionResult } from './direct-vision.mjs';
import { validateNameToneResult } from './name-tone.mjs';

const catalog = buildToneCatalog();
if (!catalog.includes('Vàng be') || !catalog.includes('Hồng đen') || !catalog.includes('Xanh dương trắng') || !catalog.includes('Vàng đen') || !catalog.includes('Nude')) throw new Error('seed catalog missing agreed names');
if (catalog.includes('Đen vàng') || catalog.includes('Đen đỏ') || catalog.includes('Trắng hồng') || catalog.includes('Đen be') || catalog.includes('Đen kem')) throw new Error('catalog must not treat ink or canvas as the leading brand');
if ('Vàng đen' !== canonicalizeToneOrder('đen vàng') || 'Hồng trắng' !== canonicalizeToneOrder('trắng hồng')) throw new Error('brand color must come before canvas');
if (catalog.some((name) => /khói|plush/i.test(name))) throw new Error('catalog must not include poetic suffixes');
if (catalogHas('Cần duyệt')) throw new Error('review label must stay outside the catalog');

const read = validateDirectVisionResult({
  tone_name: 'nâu ấm studio',
  one_liner: 'Nền be và chữ nâu giữ nhịp trang.',
  temperature: 'ấm',
  moods: ['ấm', 'sạch', 'nhẹ'],
  fits: 'nails',
  design_colors: [
    { role: 'canvas', name: 'be', hex: '#F3EAE1', why: 'nền lớn' },
    { role: 'brand', name: 'nâu', hex: '#3F3F3F', why: 'chữ và nút' },
  ],
  accent: { name: 'nâu', hex: '#3F3F3F', used_for: 'nút' },
  photo_colors_ignored: ['da'],
  contrast: { level: 'vừa', note: 'đủ đọc' },
  cohesion: { score: 8, note: 'một giọng' },
  watchouts: [],
}, 'test', 'fixture');
if (read.tone || read.tone_group) throw new Error('AI 1 must not assign a catalog tone');
if ('nâu ấm studio' !== read.descriptor) throw new Error('AI 1 free name was lost');

const picked = validateNameToneResult({ status: 'pick', tone: 'nâu be', proposed_tone: '', confidence: 0.91, reason: 'Nền be, accent nâu.' }, 'test', 'fixture', catalog);
if ('Nâu be' !== picked.tone || 'pick' !== picked.status) throw new Error('two-color warm names must keep both words');

const invented = validateNameToneResult({ status: 'pick', tone: 'nâu khói plush', proposed_tone: '', confidence: 0.99, reason: 'ép gần nhất' }, 'test', 'fixture', catalog);
if ('Nâu' !== invented.tone || 'pick' !== invented.status || 'Nâu be' === invented.tone) throw new Error('poetic words must be stripped without snapping to another pair');

const proposed = validateNameToneResult({ status: 'propose', tone: '', proposed_tone: 'vàng đỏ', confidence: 0.8, reason: 'thiếu trong catalog' }, 'test', 'fixture', catalog);
if ('Vàng đỏ' !== proposed.tone || 'pick' !== proposed.status) throw new Error('a catalog name sent as propose should still pick');

const fashion = validateNameToneResult({ status: 'propose', tone: '', proposed_tone: 'nâu taupe kem', confidence: 0.9, reason: 'không có tên trong catalog' }, 'test', 'fixture', catalog);
if ('Nâu kem' !== fashion.tone || 'pick' !== fashion.status) throw new Error('nâu taupe kem must normalize to Nâu kem');
if ('Nude' !== simplifyToneName('nude kem') || 'Nâu kem' !== simplifyToneName('nâu kem')) throw new Error('nude pairs collapse but nâu kem keeps both words');

const flipped = validateNameToneResult({ status: 'pick', tone: 'đen vàng', proposed_tone: '', confidence: 0.9, reason: 'nền đen accent vàng' }, 'test', 'fixture', catalog);
if ('Vàng đen' !== flipped.tone || 'pick' !== flipped.status) throw new Error('AI 2 must flip canvas-first names to brand-first');

const ink = validateNameToneResult({ status: 'pick', tone: 'đen be', proposed_tone: '', confidence: 0.95, reason: 'accent đen nền be' }, 'test', 'fixture', catalog, {
  tone_name: 'be nude ấm',
  one_liner: 'Trang phủ kem-be dịu với chữ đen.',
  design_colors: [
    { role: 'nền chính', name: 'be', hex: '#F6F1E9' },
    { role: 'chữ & nút', name: 'đen', hex: '#1A1A1A' },
  ],
});
if ('Nude' !== ink.tone || 'Đen be' === ink.tone) throw new Error('black text on a beige page must not become Đen be');

if (titleTone('vàng be') !== 'Vàng be') throw new Error('titleTone failed');
if (TONE_CATALOG.length < 60) throw new Error('catalog is unexpectedly small');
const warning = evaluateDirectVisionConflict({ canvas: 'be', brand: 'nâu' }, { primary_surface: 'white', canvas_mode: 'light', surface_confidence: 0.92, primary_family: 'brown', brand_candidate: { family: 'brown', score: 0.4, section_count: 3, roles: ['button', 'heading'], hex: '#3F3F3F' } }, {});
if ('hard' === warning.severity) throw new Error('Vietnamese canvas names must not hard-fail against English families');
console.log(`tone pipeline fixtures passed · ${catalog.length} catalog names`);
