import { ProviderError, errorFromResponse, parseJsonStrict, validateToneResult, TONE_SCHEMA } from './common.mjs';

const PROVIDER = 'gemini';
const MODEL = 'gemini-3.8-flash';

function outputText(payload) {
  return (payload?.steps || [])
    .filter((step) => 'model_output' === step?.type)
    .flatMap((step) => step.content || [])
    .filter((part) => 'text' === part?.type && 'string' === typeof part.text)
    .map((part) => part.text)
    .join('');
}

export async function classifyWithGemini({ apiKey, prompt, previewBuffer, fetchImpl = fetch, responseSchema = TONE_SCHEMA, validateResult = validateToneResult }) {
  if (!apiKey) throw new ProviderError('GEMINI_UNCONFIGURED', 'Gemini API key is not configured.', { provider: PROVIDER });
  let response;
  try {
    response = await fetchImpl('https://generativelanguage.googleapis.com/v1beta/interactions', {
      method: 'POST',
      headers: { 'x-goog-api-key': apiKey, 'Content-Type': 'application/json', 'Api-Revision': '2026-05-20' },
      body: JSON.stringify({
        model: MODEL,
        input: [
          { type: 'text', text: prompt },
          { type: 'image', data: previewBuffer.toString('base64'), mime_type: 'image/jpeg' },
        ],
        response_format: { type: 'text', mime_type: 'application/json', schema: responseSchema },
      }),
    });
  } catch (error) {
    throw new ProviderError('GEMINI_NETWORK', `Gemini network error: ${error.message}`, { retryable: true, provider: PROVIDER });
  }
  const body = await response.text();
  if (!response.ok) throw errorFromResponse(PROVIDER, response, body);
  let payload;
  try { payload = JSON.parse(body); } catch { throw new ProviderError('GEMINI_INVALID_RESPONSE', 'Gemini returned non-JSON HTTP output.', { provider: PROVIDER }); }
  return validateResult(parseJsonStrict(outputText(payload), PROVIDER), PROVIDER, MODEL);
}
