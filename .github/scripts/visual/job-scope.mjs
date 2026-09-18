const MAX_LIMIT = 25;
const RUN_MODES = new Set(['targeted', 'batch']);
const STAGES = new Set(['capture', 'tone', 'full', 'auto']);

export function parseTargetIds(value) {
  if (Array.isArray(value)) return uniqueIds(value);
  const text = String(value || '').trim();
  if (!text) return [];
  let decoded;
  try {
    decoded = JSON.parse(text);
  } catch {
    throw new Error('TARGET_IDS must be a JSON array of positive snapshot IDs.');
  }
  if (!Array.isArray(decoded)) throw new Error('TARGET_IDS must be a JSON array of positive snapshot IDs.');
  return uniqueIds(decoded);
}

export function normalizeJobScope(input = {}) {
  const runMode = String(input.run_mode || 'batch').trim().toLowerCase();
  const stage = String(input.stage || 'full').trim().toLowerCase();
  const limit = Math.max(1, Math.min(MAX_LIMIT, Number.parseInt(input.limit, 10) || 10));
  if (!RUN_MODES.has(runMode)) throw new Error('RUN_MODE must be targeted or batch.');
  if (!STAGES.has(stage)) throw new Error('STAGE must be capture, tone, full, or auto.');

  const targetIds = parseTargetIds(input.target_ids);
  if ('targeted' === runMode) {
    if (!targetIds.length) throw new Error('A targeted run requires at least one target ID.');
    if ('auto' === stage) throw new Error('A targeted run cannot use the auto stage.');
    return { run_mode: runMode, stage, target_ids: targetIds, limit: Math.min(limit, targetIds.length) };
  }
  return { run_mode: 'batch', stage: 'auto' === stage ? 'auto' : 'full', target_ids: [], limit };
}

/** A batch slot is a website, never a capture slot plus an analysis slot. */
export function batchCapacity(limit, waitingToneCount) {
  const total = Math.max(1, Math.min(MAX_LIMIT, Number.parseInt(limit, 10) || 10));
  const tone = Math.max(0, Math.min(total, Number.parseInt(waitingToneCount, 10) || 0));
  return { total, tone, capture: total - tone };
}

/**
 * A full run may only analyze IDs that it just captured successfully. The
 * server also enforces this; keeping the intersection here makes the runner
 * fail closed if a malformed response ever contains another queue item's ID.
 */
export function fullRunContinuationTargets(stage, capturedIds, promotedIds) {
  if ('full' !== String(stage || '').trim().toLowerCase()) return [];
  const captured = new Set(uniqueIds(Array.isArray(capturedIds) ? capturedIds : []));
  return uniqueIds(Array.isArray(promotedIds) ? promotedIds : []).filter((id) => captured.has(id));
}

function uniqueIds(values) {
  const ids = [];
  for (const value of values) {
    const id = Number.parseInt(value, 10);
    if (!Number.isSafeInteger(id) || id <= 0 || String(id) !== String(value).trim()) {
      throw new Error('TARGET_IDS must contain only positive integer snapshot IDs.');
    }
    if (!ids.includes(id)) ids.push(id);
  }
  return ids.slice(0, MAX_LIMIT);
}
