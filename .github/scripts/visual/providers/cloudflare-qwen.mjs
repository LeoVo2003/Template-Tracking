import { ProviderError, errorFromResponse, extractStructuredContent, validateToneResult, TONE_SCHEMA } from './common.mjs';

const PROVIDER = 'cloudflare';
const MODEL = '@cf/qwen/qwen3.8-27b';

export async function classifyWithCloudflareQwen({ accountId, apiToken, prompt, previewBuffer, fetchImpl = fetch, responseSchema = TONE_SCHEMA, validateResult = validateToneResult }) {
  if (!accountId || !apiToken) throw new ProviderError('CLOUDFLARE_UNCONFIGURED', 'Cloudflare Workers AI is not configured.', { provider: PROVIDER });
  let response;
  try {
    response = await fetchImpl(`https://api.cloudflare.com/client/v4/accounts/${accountId}/ai/v1/chat/completions`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${apiToken}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: MODEL,
        messages: [{ role: 'user', content: [
          { type: 'text', text: prompt },
          { type: 'image_url', image_url: { url: `data:image/jpeg;base64,${previewBuffer.toString('base64')}` } },
        ] }],
        max_completion_tokens: 260,
        temperature: 0,
        response_format: { type: 'json_object' },
      }),
    });
  } catch (error) {
    throw new ProviderError('CLOUDFLARE_NETWORK', `Cloudflare network error: ${error.message}`, { retryable: true, provider: PROVIDER });
  }
  const body = await response.text();
  if (!response.ok) throw errorFromResponse(PROVIDER, response, body);
  let payload;
  try { payload = JSON.parse(body); } catch { throw new ProviderError('CLOUDFLARE_INVALID_RESPONSE', 'Cloudflare returned non-JSON HTTP output.', { provider: PROVIDER }); }
  return validateResult(extractStructuredContent(payload, PROVIDER), PROVIDER, MODEL);
}
