import { ProviderError, describeStructuredShape, errorFromResponse, extractStructuredContent, validateToneResult, TONE_SCHEMA } from './common.mjs';

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
        reasoning_effort: 'low',
        max_completion_tokens: 1200,
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
  try {
    return validateResult(extractStructuredContent(payload, PROVIDER), PROVIDER, MODEL);
  } catch (error) {
    if (error instanceof ProviderError && 'CLOUDFLARE_INVALID_SCHEMA' === error.code) {
      const message = (payload?.result || payload)?.choices?.[0]?.message;
      error.message = `${error.message} Response shape: ${JSON.stringify(describeStructuredShape(payload))}. Message shape: ${JSON.stringify(describeStructuredShape(message))}`;
    }
    throw error;
  }
}
