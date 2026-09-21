export class PageValidationError extends Error {
  constructor(code, message, details = {}) {
    super(message);
    this.name = 'PageValidationError';
    this.code = code;
    this.details = details;
  }
}

/** Security blocks are actionable with the self-hosted local runner. */
export function isSecurityBlockError(error) {
  const code = String(error?.code || '').toUpperCase();
  if (['HTTP_401', 'HTTP_403', 'CF_CHALLENGE', 'CAPTCHA'].includes(code)) return true;
  return /security|cloudflare|captcha|waf|bot verification|access denied/i.test(String(error?.message || ''));
}

const hostname = (value) => {
  try { return new URL(value).hostname.replace(/^www\./i, '').toLowerCase(); } catch { return ''; }
};

function redirectCount(response) {
  let count = 0;
  let request = response?.request?.();
  while (request?.redirectedFrom?.()) {
    count += 1;
    request = request.redirectedFrom();
  }
  return count;
}

export async function validatePage(page, response, requestedUrl) {
  if (!response) throw new PageValidationError('NAV_TIMEOUT', 'Homepage navigation returned no response.', { requested_url: requestedUrl, final_url: page.url() });
  const httpStatus = response?.status?.() || 0;
  const finalUrl = page.url();
  const title = await page.title().catch(() => '');
  const bodyText = await page.locator('body').innerText({ timeout: 4000 }).catch(() => '');
  const text = `${title}\n${bodyText}`.replace(/\s+/g, ' ').trim();
  const details = { requested_url: requestedUrl, final_url: finalUrl, http_status: httpStatus, redirect_count: redirectCount(response), page_title: title };
  if (httpStatus >= 400) throw new PageValidationError(`HTTP_${httpStatus}`, `Homepage returned HTTP ${httpStatus}.`, details);
  if (/performing security verification|verify you are human|checking your browser|just a moment\.?/i.test(text)) throw new PageValidationError('CF_CHALLENGE', 'Homepage is behind a bot/security challenge.', details);
  if (/captcha|recaptcha|hcaptcha/i.test(text)) throw new PageValidationError('CAPTCHA', 'Homepage requires CAPTCHA verification.', details);
  if (/account suspended|hosting suspended|site suspended|domain has expired/i.test(text)) throw new PageValidationError('MAINTENANCE', 'Homepage is suspended or unavailable.', details);
  if (/under maintenance|coming soon|site is temporarily unavailable|maintenance mode/i.test(text)) throw new PageValidationError('MAINTENANCE', 'Homepage is in maintenance mode.', details);
  if (/wp-login\.php|log in to wordpress|login to continue/i.test(`${finalUrl}\n${text}`)) throw new PageValidationError('LOGIN_WALL', 'Homepage redirects to a login wall.', details);
  if (/domain for sale|buy this domain|parked free|this domain is parked/i.test(text)) throw new PageValidationError('PARKED_DOMAIN', 'Homepage appears to be a parked domain.', details);
  if (hostname(requestedUrl) && hostname(finalUrl) && hostname(requestedUrl) !== hostname(finalUrl)) throw new PageValidationError('BAD_REDIRECT', 'Homepage redirected to an unexpected domain.', details);
  if (text.length < 40 && (await page.locator('body').count()) > 0) throw new PageValidationError('EMPTY_PAGE', 'Homepage rendered with too little content.', details);
  return { ...details, valid: true, blocked_reason: null };
}
