import assert from 'node:assert/strict';
import test from 'node:test';
import { homepageCandidates } from '../.github/scripts/visual/homepage-resolver.mjs';

test('root URL prefers /home/ then preserves root as a fallback', () => {
  assert.deepEqual(homepageCandidates('https://example.com/'), ['https://example.com/home/', 'https://example.com/']);
});

test('a meaningful stored path is never rewritten to /home/', () => {
  assert.deepEqual(homepageCandidates('https://example.com/special-landing/'), ['https://example.com/special-landing/']);
});

test('a root URL with query evidence is kept exactly as stored', () => {
  assert.deepEqual(homepageCandidates('https://example.com/?preview=1'), ['https://example.com/?preview=1']);
});
