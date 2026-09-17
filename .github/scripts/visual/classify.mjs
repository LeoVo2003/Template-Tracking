export const tones = ['Vàng kem sáng', 'Vàng đen', 'Vàng trắng', 'Đen vàng', 'Hồng xanh trắng', 'Hồng trắng', 'Hồng đen', 'Đỏ trắng', 'Đỏ hồng', 'Đỏ đen', 'Nâu kem', 'Nâu trắng', 'Nâu vàng', 'Nâu đen', 'Xanh vàng', 'Xanh trắng', 'Xanh đen', 'Xanh kem', 'Đen trắng', 'Trắng kem', 'Tím hồng', 'Tím trắng', 'Tím đen', 'Cam trắng', 'Cam đen', 'Cần duyệt'];

export function tonePrompt(evidence) {
  const deterministic = {
    metrics_version: evidence.metrics_version || 0,
    candidate: evidence.candidate || 'Cần duyệt',
    candidate_confidence: evidence.candidate_confidence ?? null,
    primary_family: evidence.primary_family || null,
    secondary_family: evidence.secondary_family || null,
    surface: evidence.surface || null,
    coverage: evidence.coverage || null,
    average_saturation: evidence.average_saturation ?? null,
    average_luminance: evidence.average_luminance ?? null,
    warm_cool_tendency: evidence.warm_cool_tendency || null,
    contrast_level: evidence.contrast_level || null,
    dominant_structural_colors: (evidence.dominant_structural_colors || []).slice(0, 6),
  };
  return `Classify one fixed visual tone from the supplied AI preview. The preview and deterministic metrics come from the exact same rendered page state as the stored full screenshot. Judge structural UI only: repeated section backgrounds, header/footer bars, buttons, borders and navigation. Ignore every photo/media object, nail/skin/flower/product image, logo detail, icon, text color, one-off button, and decorative artifact. UI metrics are 80% of the decision; the screenshot preview is only a 20% tie-breaker. Pale rose is pink; beige/taupe/nude is brown or cream; warm gold is yellow/gold, never red or pink. Red requires repeated true red UI surfaces, not red objects in photos. Dark/black requires substantial dark UI area, not body text. If the leading hue or pair is not clear, choose Cần duyệt.

Measured evidence: ${evidence.text || 'No reliable metric summary.'}
Deterministic evidence JSON (area-weighted UI; treat as stronger than the image): ${JSON.stringify(deterministic)}
Allowed labels: ${tones.join(', ')}.
Pair meanings: Vàng kem sáng = light cream/yellow UI; Vàng đen/Vàng trắng/Đen vàng = yellow/gold paired with black/white; Hồng xanh trắng/Hồng trắng/Hồng đen = pink paired with green-white/light or black; Đỏ trắng/Đỏ hồng/Đỏ đen require repeated true red; Nâu kem/Nâu trắng/Nâu vàng/Nâu đen = taupe, beige, nude or brown paired with cream, white, gold or black; Xanh vàng/Xanh trắng/Xanh đen/Xanh kem = green/blue paired with gold, white, black or cream; remaining labels follow the same structural pair rule.

Return JSON only: {"tone":"one allowed label","confidence":"high|medium|low","reason":"one short Vietnamese sentence about structural UI colors"}.`;
}

function parseTone(response, evidence) {
  const value = response?.result?.response ?? response?.response ?? response?.result ?? response;
  const text = typeof value === 'string' ? value : JSON.stringify(value);
  const match = text.match(/\{[\s\S]*\}/);
  let parsed = {};
  if (match) { try { parsed = JSON.parse(match[0]); } catch { parsed = {}; } }
  const normalized = text.toLocaleLowerCase('vi-VN');
  const explicit = tones.find((tone) => normalized.includes(tone.toLocaleLowerCase('vi-VN')));
  const tone = tones.includes(parsed.tone) ? parsed.tone : (explicit || 'Cần duyệt');
  const confidence = ['high', 'medium', 'low'].includes(parsed.confidence) ? parsed.confidence : 'low';
  return { tone, confidence, reason: String(evidence.text || '').slice(0, 500) };
}

export async function classifyTone({ snapshotId, previewBuffer, evidence, jobToken, cloudflareAccount, cloudflareToken, postJson }) {
  if (!cloudflareAccount || !cloudflareToken) return { skipped: true };
  const response = await fetch(`https://api.cloudflare.com/client/v4/accounts/${cloudflareAccount}/ai/run/@cf/meta/llama-3.2-11b-vision-instruct`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${cloudflareToken}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ prompt: tonePrompt(evidence), image: `data:image/jpeg;base64,${previewBuffer.toString('base64')}`, max_tokens: 160, temperature: 0.1 }),
  });
  if (response.status === 429) {
    await postJson({ mode: 'tone_deferred', snapshot_id: snapshotId, job_token: jobToken });
    return { skipped: true, quota: true };
  }
  if (!response.ok) throw new Error(`Llama Vision failed: HTTP ${response.status} ${(await response.text()).slice(0, 500)}`);
  const tone = parseTone(await response.json(), evidence);
  await postJson({ mode: 'tone', snapshot_id: snapshotId, job_token: jobToken, ...tone });
  return tone;
}
