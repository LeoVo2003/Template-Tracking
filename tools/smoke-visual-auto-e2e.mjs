import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';

const events = [];
let claims = 0;
const server = createServer(async (request, response) => {
  const chunks = [];
  for await (const chunk of request) chunks.push(chunk);
  const body = chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}') : {};
  if (request.url.endsWith('/config')) {
    response.setHeader('Content-Type', 'application/json');
    response.end(JSON.stringify({ mode: 'auto', ai_strategy: 'smart', auto_accept_threshold: 0.85, gemini_daily_budget_per_key: 8, classifier_mode: 'direct_vision' }));
    return;
  }
  if (request.url.endsWith('/jobs/claim')) {
    claims += 1;
    assert.equal(body.run_mode, 'batch');
    assert.equal(body.limit, 11);
    response.setHeader('Content-Type', 'application/json');
    response.end(JSON.stringify({ items: [], claimed_ids: [], skipped_ids: [] }));
    return;
  }
  if (request.url.endsWith('/run-heartbeat') || request.url.endsWith('/run-complete')) {
    events.push({ path: request.url, body });
    response.setHeader('Content-Type', 'application/json');
    response.end(JSON.stringify({ success: true }));
    return;
  }
  response.statusCode = 404;
  response.end('not found');
});

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const { port } = server.address();
try {
  const child = spawn(process.execPath, ['.github/scripts/capture-visual-tone.mjs'], {
    cwd: process.cwd(),
    env: {
      ...process.env,
      MAC_TRACKER_SITE_URL: `http://127.0.0.1:${port}`,
      MAC_TRACKER_AUTOMATION_SECRET: 'local-smoke-secret',
      FREE_ONLY: 'true',
      WORKFLOW_EVENT: 'schedule',
      RUN_MODE: 'batch',
      VISUAL_STAGE: 'full',
      TARGET_IDS: '[]',
      SOURCE_ACTION: 'scheduled_auto',
      BATCH_LIMIT: '11',
      GITHUB_RUN_ID: '99112233',
      GITHUB_RUN_ATTEMPT: '1',
      GITHUB_REPOSITORY: 'LeoVo2003/Template-Tracking',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (chunk) => { stdout += chunk; });
  child.stderr.on('data', (chunk) => { stderr += chunk; });
  const code = await new Promise((resolve) => child.on('close', resolve));
  assert.equal(code, 0, stderr || stdout);
  assert.equal(claims, 1, 'AUTO schedule must claim exactly once even when the queue is empty');
  assert.equal(events.some((event) => event.path.endsWith('/run-heartbeat') && event.body.source_action === 'scheduled_auto'), true);
  assert.equal(events.some((event) => event.path.endsWith('/run-complete') && event.body.current_step === 'completed'), true);
  assert.match(stdout, /Claimed logical websites: 0/);
  assert.doesNotMatch(stdout, /MANUAL mode/);
  process.stdout.write(`${JSON.stringify({ mode: 'auto', claims, telemetry_events: events.length, empty_queue: 'clean_exit' })}\n`);
} finally {
  await new Promise((resolve) => server.close(resolve));
}
