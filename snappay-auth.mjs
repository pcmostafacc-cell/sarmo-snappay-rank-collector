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
    // A missing input is diagnosed below. The SSO app may redirect after page load.
  }
  if (!isLogin(page.url())) return { context, page };
  if (!auth.phone) throw new Error('SnappPay login required; set the phone with /login in Bale');

  await page.locator('[data-testid="phone-input"] input, input[data-testid="phone-input"]').first().fill(auth.phone, { timeout: 20000 });
  await page.locator('[data-testid="phone-submit-button"]').click();
  await page.locator('[data-testid="otp-input"]').waitFor({ timeout: 20000 });
  await wp(AUTH_PATH, {
    method: 'POST', body: JSON.stringify({ action: 'begin_challenge' }),
  });
  const code = await waitForCode(wp);
  const otpField = page.locator('[data-testid="otp-input"] input, input[data-testid="otp-input"]').first();
  const failedResponses = [];
  page.on('response', response => {
    if (response.status() >= 400 && failedResponses.length < 5) {
      const url = new URL(response.url());
      if (url.hostname.endsWith('snapppay.ir')) failedResponses.push(response.status() + ' ' + url.hostname);
    }
  });
  await otpField.pressSequentially(code, { delay: 80 });
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
      .map(value => value.trim()).filter(Boolean).slice(0, 2);
    const testids = await page.locator('[data-testid]').evaluateAll(elements =>
      [...new Set(elements.map(element => element.getAttribute('data-testid')))].filter(Boolean).slice(0, 15));
    const messages = await page.locator('[data-testid="otp-error"], [data-testid="toast-text"]')
      .allTextContents().catch(() => []);
    const safeMessages = messages.map(value => value.replace(/[0-9۰-۹٠-٩]/g, '#').trim())
      .filter(Boolean).slice(0, 2);
    throw new Error('SnappPay did not return after OTP (host: ' + host + '; alerts: ' + (alerts.join(' | ') || 'none') + '; messages: ' + (safeMessages.join(' | ') || 'none') + '; testids: ' + testids.join(',') + '; HTTP: ' + (failedResponses.join(',') || 'none') + ')');
  }
  await page.locator('[data-testid="search-typing-input"]').waitFor({ timeout: 20000 });
  await wp(AUTH_PATH, {
    method: 'POST', body: JSON.stringify({
      action: 'save_session', session: await context.storageState(),
    }),
  });
  return { context, page };
}
