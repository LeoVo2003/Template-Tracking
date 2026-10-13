import { ProviderError, errorFromResponse, extractStructuredContent } from './common.mjs';

const PROVIDER = 'groq_text';
// llama-3.3-70b-versatile was decommissioned by Groq on 2026-08-16 (404 model_not_found).
// Kept as a named export for compatibility; the classify chain no longer uses it as primary.
export const GROQ_TEXT_PRIMARY = 'openai/gpt-oss-20b';
export const GROQ_TEXT_FALLBACK = 'openai/gpt-oss-20b';

export async function classifyWithGroqText({ apiKey, prompt, model = GROQ_TEXT_PRIMARY, fetchImpl = fetch, validateResult }) {
  if (!apiKey) throw new ProviderError('GROQ_UNCONFIGURED', 'Groq API key is not configured.', { provider: PROVIDER });
  if ('function' !== typeof validateResult) throw new ProviderError('GROQ_TEXT_INVALID_SETUP', 'A text-result validator is required.', { provider: PROVIDER });
  let response;
  try {
    response = await fetchImpl('https://api.groq.com/openai/v1/chat/completions', {
      method: 'POST',
      headers: { Authorization: `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model,
        temperature: 0,
        max_completion_tokens: 800,
        messages: [
          { role: 'system', content: 'Bạn chỉ đọc JSON đã cho và đặt tên tone. Không bịa hex. Chỉ trả JSON.' },
          { role: 'user', content: prompt },
        ],
        response_format: { type: 'json_object' },
      }),
    });
  } catch (error) {
    throw new ProviderError('GROQ_NETWORK', `Groq text network error: ${error.message}`, { retryable: true, provider: PROVIDER });
  }
  const body = await response.text();
  if (!response.ok) throw errorFromResponse(PROVIDER, response, body);
  let payload;
  try { payload = JSON.parse(body); } catch { throw new ProviderError('GROQ_INVALID_RESPONSE', 'Groq text returned non-JSON HTTP output.', { provider: PROVIDER }); }
  return validateResult(extractStructuredContent(payload, PROVIDER), PROVIDER, model);
}
