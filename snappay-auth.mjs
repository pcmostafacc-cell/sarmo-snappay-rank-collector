const SEARCH_URL = 'https://app.snapppay.ir/search';
const AUTH_PATH = '/wp-json/sarmo-monitor/v1/collector-auth';

function isLogin(url) {
  try { return new URL(url).hostname === 'sso.snapppay.ir'; }
  catch { return false; }
}

async function waitForCode(wp) {
  for (let attempt = 0; attempt < 45; attempt++) {
    const answer = await wp(AUTH_PATH, {
      method: 'POST', body: JSON.stringify({ action: 'take_otp' }),
    });
    if (answer.code) return answer.code;
    await new Promise(resolve => setTimeout(resolve, 4000));
  }
  throw new Error('SnappPay OTP was not received in Bale within 3 minutes');
}

async function abortChallenge(wp) {
  try {
    await wp(AUTH_PATH, {
      method: 'POST', body: JSON.stringify({ action: 'abort_challenge' }),
    });
  } catch { /* Keep the original authentication error. */ }
}

export function assertLoginArmed(auth) {
  if (!auth?.login_armed) {
    throw new Error('SnappPay login required; send /login in the owner Bale chat to authorize one OTP attempt');
  }
}

export async function claimOneTimeLogin(wp, auth) {
  assertLoginArmed(auth);
  const claim = await wp(AUTH_PATH, {
    method: 'POST', body: JSON.stringify({ action: 'claim_login_attempt' }),
  });
  const phone = String(claim.phone || '');
  if (!/^09[0-9]{9}$/.test(phone)) {
    await abortChallenge(wp);
    throw new Error('SnappPay one-time login authorization returned no valid phone');
  }
  return phone;
}

export async function openSnappPay(browser, wp) {
  const auth = await wp(AUTH_PATH);
  const context = await browser.newContext({
    locale: 'fa-IR',
    ...(auth.session ? { storageState: auth.session } : {}),
  });
  const page = await context.newPage();
  await page.goto(SEARCH_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
  try {
    await page.locator('[data-testid="search-typing-input"]').waitFor({ timeout: 12000 });
  } catch {
    // Diagnose the SSO route below. A non-SSO failure will be handled by the collector.
  }
  if (!isLogin(page.url())) return { context, page };

  // Fail closed: scheduled runs cannot send an SMS unless the owner armed one login
  // attempt through Bale and WordPress atomically consumes that authorization.
  const phone = await claimOneTimeLogin(wp, auth);

  try {
    const phoneFailures = [];
    page.on('response', response => {
      if (response.status() >= 400 && phoneFailures.length < 5) {
        try {
          const url = new URL(response.url());
          if (url.hostname.endsWith('snapppay.ir')) phoneFailures.push(`${response.status()} ${url.hostname}`);
        } catch { /* Ignore non-URL responses. */ }
      }
    });
    await page.locator('[data-testid="phone-input"] input, input[data-testid="phone-input"]').first().fill(phone, { timeout: 20000 });
    await page.locator('[data-testid="phone-submit-button"]').click();
    try {
      await page.locator('[data-testid="otp-input"]').waitFor({ timeout: 20000 });
    } catch {
      const testids = await page.locator('[data-testid]').evaluateAll(elements =>
        [...new Set(elements.map(element => element.getAttribute('data-testid')))].filter(Boolean).slice(0, 15));
      const messages = await page.locator('[data-testid*="error"], [data-testid*="toast"], [role="alert"]')
        .allTextContents().catch(() => []);
      const safeMessages = messages.map(value => value.replace(/[0-9۰-۹٠-٩]/g, '#').trim().slice(0, 100))
        .filter(Boolean).slice(0, 2);
      throw new Error(`SnappPay phone step did not show OTP (host: ${new URL(page.url()).hostname}; messages: ${safeMessages.join(' | ') || 'none'}; testids: ${testids.join(',')}; HTTP: ${phoneFailures.join(',') || 'none'})`);
    }

    const code = await waitForCode(wp);
    const otpInputs = page.locator('[data-testid="otp-input"] input');
    const inputCount = await otpInputs.count();
    const otpField = inputCount ? otpInputs.first() : page.locator('input[data-testid="otp-input"]').first();
    const failedResponses = [];
    page.on('response', response => {
      if (response.status() >= 400 && failedResponses.length < 5) {
        try {
          const url = new URL(response.url());
          if (url.hostname.endsWith('snapppay.ir')) failedResponses.push(`${response.status()} ${url.hostname}`);
        } catch { /* Ignore non-URL responses. */ }
      }
    });
    if (inputCount === code.length && inputCount > 1) {
      for (let index = 0; index < code.length; index++) await otpInputs.nth(index).fill(code[index]);
    } else {
      await otpField.fill(code);
    }
    const enteredCode = inputCount > 1
      ? (await Promise.all(Array.from({ length: inputCount }, (_, index) => otpInputs.nth(index).inputValue()))).join('')
      : await otpField.inputValue();
    if (enteredCode !== code) {
      throw new Error(`SnappPay OTP input mismatch (fields ${inputCount}; expected length ${code.length}; entered length ${enteredCode.length})`);
    }
    const submit = page.locator('[data-testid="otp-submit-button"]');
    await submit.waitFor({ timeout: 10000 });
    await submit.click();
    try {
      await page.waitForURL(url => url.hostname === 'app.snapppay.ir', {
        timeout: 30000, waitUntil: 'commit',
      });
    } catch {
      const host = new URL(page.url()).hostname;
      const alerts = (await page.locator('[role="alert"]').allTextContents().catch(() => []))
        .map(value => value.replace(/[0-9۰-۹٠-٩]/g, '#').trim()).filter(Boolean).slice(0, 2);
      const testids = await page.locator('[data-testid]').evaluateAll(elements =>
        [...new Set(elements.map(element => element.getAttribute('data-testid')))].filter(Boolean).slice(0, 15));
      const messages = await page.locator('[data-testid="otp-error"], [data-testid="toast-text"]')
        .allTextContents().catch(() => []);
      const safeMessages = messages.map(value => value.replace(/[0-9۰-۹٠-٩]/g, '#').trim())
        .filter(Boolean).slice(0, 2);
      throw new Error(`SnappPay did not return after OTP (host: ${host}; alerts: ${alerts.join(' | ') || 'none'}; messages: ${safeMessages.join(' | ') || 'none'}; testids: ${testids.join(',')}; HTTP: ${failedResponses.join(',') || 'none'})`);
    }
    await page.locator('[data-testid="search-typing-input"]').waitFor({ timeout: 20000 });
    await wp(AUTH_PATH, {
      method: 'POST', body: JSON.stringify({
        action: 'save_session', session: await context.storageState(),
      }),
    });
    return { context, page };
  } catch (error) {
    await abortChallenge(wp);
    throw error;
  }
}
