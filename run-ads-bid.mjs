import { appendFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { AdsLoginRequired, applyApprovedBid } from './ads-panel-reader.mjs';

const { SARMO_WP_URL, SARMO_ADS_BID_WP_USER, SARMO_ADS_BID_WP_APP_PASSWORD } = process.env;
if (!SARMO_WP_URL || !SARMO_ADS_BID_WP_USER || !SARMO_ADS_BID_WP_APP_PASSWORD) {
  throw new Error('Configure the WordPress Ads Executor credentials.');
}
const base = new URL(SARMO_WP_URL);
if (base.protocol !== 'https:' || base.username || base.password) throw new Error('Use an HTTPS WordPress URL.');
const authorization = 'Basic ' + Buffer.from(SARMO_ADS_BID_WP_USER + ':' + SARMO_ADS_BID_WP_APP_PASSWORD).toString('base64');

async function wp(route, payload) {
  const response = await fetch(new URL('/wp-json/sarmo-monitor/v1/ads-bid-' + route, base), {
    method: payload === undefined ? 'GET' : 'POST',
    headers: { Authorization: authorization, 'Content-Type': 'application/json' },
    body: payload === undefined ? undefined : JSON.stringify(payload),
    redirect: 'error', signal: AbortSignal.timeout(20000),
  });
  if (route === 'claim' && response.status === 409) return null;
  if (!response.ok) throw new Error('WordPress Ads Bid ' + route + ': HTTP ' + response.status);
  return response.json();
}

async function run() {
  const { job, session } = await wp('next');
  if (job && !session) {
    await wp('login-required', {});
    console.log('Ads bid is waiting for manual sign-in.');
  }
  const ready = Boolean(job && session);
  if (process.argv.includes('--preflight')) {
    if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `run=${ready}\n`);
    return;
  }
  if (!ready) return;

  const { SARMO_ADS_BID_WP_USER: _user, SARMO_ADS_BID_WP_APP_PASSWORD: _password, ...browserEnv } = process.env;
  const browser = await chromium.launch({ headless: true, env: browserEnv });
  let permit;
  try {
    const context = await browser.newContext({ locale: 'fa-IR', storageState: session });
    const result = await applyApprovedBid(context, job, async () => {
      permit = await wp('claim', { id: job.id });
      return permit?.expires;
    });
    if (permit === undefined) permit = await wp('claim', { id: job.id });
    if (permit) {
      await wp('result', { id: job.id, claim: permit.claim, ...result });
      console.log('Ads bid outcome: ' + result.state);
    }
  } catch (error) {
    if (!(error instanceof AdsLoginRequired)) throw error;
    await wp('login-required', {});
    console.log('Ads bid requires a fresh manual sign-in.');
  } finally { await browser.close(); }
}

// Browser exceptions can contain page text or identifying URLs.
await run().catch(() => {
  console.error('The Ads bid run did not complete. Check the job status in Bale before requesting another change.');
  process.exitCode = 1;
});
