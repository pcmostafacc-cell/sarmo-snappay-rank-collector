import { chromium } from 'playwright';
import { appendFileSync } from 'node:fs';
import { AdsLoginRequired, collectAdsSnapshot } from './ads-panel-reader.mjs';

const { SARMO_WP_URL, SARMO_ADS_WP_USER, SARMO_ADS_WP_APP_PASSWORD } = process.env;
if (!SARMO_WP_URL || !SARMO_ADS_WP_USER || !SARMO_ADS_WP_APP_PASSWORD) {
  throw new Error('Configure the WordPress Ads Reader credentials.');
}
const base = new URL(SARMO_WP_URL);
if (base.protocol !== 'https:' || base.username || base.password) {
  throw new Error('Use an HTTPS WordPress URL without credentials in the URL.');
}
const basic = 'Basic ' + Buffer.from(SARMO_ADS_WP_USER + ':' + SARMO_ADS_WP_APP_PASSWORD).toString('base64');
async function wp(route, payload) {
  const response = await fetch(new URL('/wp-json/sarmo-monitor/v1/' + route, base), {
    method: payload === undefined ? 'GET' : 'POST',
    headers: { Authorization: basic, 'Content-Type': 'application/json' },
    body: payload === undefined ? undefined : JSON.stringify(payload),
    redirect: 'error',
    signal: AbortSignal.timeout(25000),
  });
  if (!response.ok) throw new Error(route + ': HTTP ' + response.status);
  return response.json();
}

const state = await wp('ads-session');
if (state.configured !== true) throw new Error('Configure the private login browser in WordPress.');
if (!state.session) await wp('ads-login-required', {});
if (process.argv.includes('--preflight')) {
  if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, 'run=' + Boolean(state.session) + '\n');
  if (!state.session) console.log('Ads report is waiting for manual sign-in.');
} else if (state.session) {
  const browserEnv = { ...process.env };
  delete browserEnv.SARMO_ADS_WP_APP_PASSWORD;
  delete browserEnv.SARMO_ADS_WP_USER;
  const browser = await chromium.launch({ headless: true, env: browserEnv });
  try {
    const context = await browser.newContext({ locale: 'fa-IR', storageState: state.session });
    const data = await collectAdsSnapshot(context);
    const saved = await wp('ads-snapshot', data);
    console.log('Stored Ads campaigns: ' + saved.count);
  } catch (error) {
    if (error instanceof AdsLoginRequired) {
      await wp('ads-login-required', {});
      console.log('Ads requires a fresh manual sign-in.');
      process.exitCode = 2;
    } else {
      console.error('Ads did not yield a complete verified report.');
      process.exitCode = 1;
    }
  } finally { await browser.close(); }
}
