import { ProviderError, errorFromResponse, parseJsonStrict, validateToneResult, TONE_SCHEMA } from './common.mjs';

const PROVIDER = 'groq';
const MODEL = 'qwen/qwen3.8-27b';

export async function classifyWithGroq({ apiKey, prompt, previewBuffer, fetchImpl = fetch }) {
  if (!apiKey) throw new ProviderError('GROQ_UNCONFIGURED', 'Groq API key is not configured.', { provider: PROVIDER });
  let response;
  try {
    response = await fetchImpl('https://api.groq.com/openai/v1/chat/completions', {
      method: 'POST',
      headers: { Authorization: `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: MODEL,
        reasoning_effort: 'low',
        temperature: 0,
        max_completion_tokens: 260,
        messages: [{ role: 'user', content: [
          { type: 'text', text: prompt },
          { type: 'image_url', image_url: { url: `data:image/jpeg;base64,${previewBuffer.toString('base64')}` } },
        ] }],
        response_format: { type: 'json_schema', json_schema: { name: 'visual_tone', strict: true, schema: TONE_SCHEMA } },
      }),
    });
  } catch (error) {
    throw new ProviderError('GROQ_NETWORK', `Groq network error: ${error.message}`, { retryable: true, provider: PROVIDER });
  }
  const body = await response.text();
  if (!response.ok) throw errorFromResponse(PROVIDER, response, body);
  let payload;
  try { payload = JSON.parse(body); } catch { throw new ProviderError('GROQ_INVALID_RESPONSE', 'Groq returned non-JSON HTTP output.', { provider: PROVIDER }); }
  return validateToneResult(parseJsonStrict(payload?.choices?.[0]?.message?.content, PROVIDER), PROVIDER, MODEL);
}
