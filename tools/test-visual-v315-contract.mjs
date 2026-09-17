import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { validateToneResult } from '../.github/scripts/visual/providers/common.mjs';

const activator = await readFile('includes/class-mac-tracker-activator.php', 'utf8');
const repository = await readFile('includes/class-mac-tracker-repository.php', 'utf8');
const service = await readFile('includes/class-mac-tracker-visual-service.php', 'utf8');
const admin = await readFile('includes/class-mac-tracker-admin.php', 'utf8');

test('V3.15 provider schema carries mixed canvas surfaces and navy semantics', () => {
  const result = validateToneResult({ canvas_mode: 'mixed', canvas_family: 'white', primary_surface: 'white', secondary_surface: 'navy', primary_family: 'navy', secondary_family: 'pink', style_tone: 'dark_modern', confidence: 0.91, reason: 'Large white panels and navy structural sections.', needs_review: false }, 'fixture', 'fixture-v3.15');
  assert.equal(result.primary_surface, 'white');
  assert.equal(result.secondary_surface, 'navy');
  assert.equal(result.primary_family, 'navy');
});

test('V3.15 persists exact tone separately without changing the legacy tone field', () => {
  assert.match(activator, /tone_group varchar\(64\) NOT NULL DEFAULT ''/);
  assert.match(activator, /precise_tone varchar\(128\) NOT NULL DEFAULT ''/);
  assert.match(repository, /'tone_group'\s*=> sanitize_text_field/);
  assert.match(repository, /'precise_tone'\s*=> sanitize_text_field/);
  assert.match(service, /'tone_group'\s*=> sanitize_text_field/);
  assert.match(service, /'precise_tone'\s*=> sanitize_text_field/);
  assert.match(admin, /Precise: /);
});

test('manual review remains locked and does not invent an AI precise label', () => {
  const manualStart = repository.indexOf('public function save_manual_visual_tone');
  const manual = repository.slice(manualStart, repository.indexOf('public function unlock_manual_visual_tone', manualStart));
  assert.match(manual, /'manual_locked'\s*=> 1/);
  assert.match(manual, /'precise_tone'\s*=> ''/);
});
