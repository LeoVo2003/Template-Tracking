import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

const [main, repository, github, capture] = await Promise.all([
  readFile('.github/scripts/visual/main.mjs', 'utf8'),
  readFile('includes/class-mac-tracker-repository.php', 'utf8'),
  readFile('includes/class-mac-tracker-github-actions.php', 'utf8'),
  readFile('.github/scripts/visual/capture.mjs', 'utf8'),
]);

test('homepage capture stores resolver evidence and preserves target semantics', () => {
  assert.match(capture, /resolveHomepage\(page, requestedUrl\)/);
  assert.match(capture, /candidate_urls: candidateUrls/);
  assert.match(capture, /resolved_capture_url: resolvedCaptureUrl/);
  assert.match(capture, /resolution_strategy: resolutionStrategy/);
});

test('permanent access failures block while transient capture failures remain retryable', () => {
  for (const code of ['HTTP_401', 'HTTP_403', 'HTTP_404', 'HOMEPAGE_RESOLUTION_FAILED']) assert.match(repository, new RegExp(`'${code}'`));
  assert.match(repository, /retry_wait/);
  assert.match(main, /'HTTP_403'/);
  assert.match(main, /Capture failed #\$\{item\.id\}: \$\{error\.message\}/);
});

test('workflow logs preserve real capture failures and normalize unsafe bytes before JSON', () => {
  assert.match(github, /download_visual_job_log/);
  assert.match(github, /wp_check_invalid_utf8/);
  assert.match(github, /Capture failed #\\d\+:/);
  assert.match(github, /Tone failed #\\d\+:/);
  assert.match(github, /GITHUB_LOG_UNAVAILABLE/);
});
