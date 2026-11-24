import { observationPayload } from './observation-payload.mjs';
import { chromium } from 'playwright';
import { appendFileSync } from 'node:fs';
import { collectKeyword } from './snappay-playwright-collector.mjs';
import { isProviderRateLimited, openSnappPay } from './snappay-auth.mjs';
import { assertCollectorContract } from './collector-contract.mjs';

const required = ['SARMO_WP_URL', 'SARMO_WP_USER', 'SARMO_WP_APP_PASSWORD'];
for (const key of required) {
  if (!process.env[key]) throw new Error(`Missing ${key}`);
}

const base = new URL(process.env.SARMO_WP_URL);
if (base.protocol !== 'https:') throw new Error('WordPress URL must use HTTPS');
const authorization = 'Basic ' + Buffer.from(
  `${process.env.SARMO_WP_USER}:${process.env.SARMO_WP_APP_PASSWORD}`
).toString('base64');

async function wp(path, options = {}) {
  const response = await fetch(new URL(path, base), {
    ...options,
    headers: { Authorization: authorization, 'Content-Type': 'application/json', ...options.headers },
    signal: AbortSignal.timeout(20000),
    redirect: 'error',
  });
  if (!response.ok) throw new Error(`WordPress ${path}: HTTP ${response.status}`);
  return response.json();
}

const status = await wp('/wp-json/sarmo-monitor/v1/collector-status');
const preflight = process.argv.includes('--preflight');
try {
  assertCollectorContract(status);
} catch (error) {
  if (!preflight) throw error;
  if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, 'run=false\n');
  console.log('WordPress plugin contract is outdated; skipping browser installation and collection.');
  process.exit(0);
}

const keywords = await wp('/wp-json/sarmo-monitor/v1/keywords');
const queries = Object.keys(keywords);
if (!queries.length) process.exit(0);
if (queries.length > 10) throw new Error('Monitoring is capped at 10 keywords to control runner usage');

const auth = await wp('/wp-json/sarmo-monitor/v1/collector-auth');
const manualLogin = auth.manual_browser_login === true;
async function reportLogin(state) {
  await wp('/wp-json/sarmo-monitor/v1/codespaces-status', {
    method: 'POST', body: JSON.stringify({ state }),
  });
}
const runBrowser = manualLogin ? Boolean(auth.session) : !isProviderRateLimited(auth);
if (manualLogin && !runBrowser) await reportLogin('required');
if (preflight) {
  if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `run=${runBrowser}\n`);
  console.log(runBrowser ? 'Browser collection can run.' : 'Waiting for authorized login.');
  process.exit(0);
}
if (!runBrowser) {
  console.log('Waiting for authorized login. Check Bale for status.');
  process.exit(0);
}

const envList = (name, fallback = []) => {
  const value = process.env[name];
  return value ? value.split(',').map(item => item.trim()).filter(Boolean) : fallback;
};
const identity = {
  merchantAliases: envList('SARMO_MERCHANT_ALIASES', ['سارمو استایل']),
  productIds: envList('SARMO_PRODUCT_IDS'),
  sellerIds: envList('SARMO_SELLER_IDS'),
};

const safeError = error => String(error)
  .replace(/09[0-9]{9}/g, '[phone masked]')
  .replace(/\b[0-9]{4,8}\b/g, '[code masked]')
  .slice(0, 300);

const browser = await chromium.launch({ headless: true });
let validCount = 0;
let invalidCount = 0;
let manualLoginRequired = false;
try {
  let page;
  let authError = null;
  try {
    ({ page } = await openSnappPay(browser, wp, auth));
  } catch (error) {
    if (manualLogin && error.code === 'manual_login_required') {
      manualLoginRequired = true;
    } else {
      authError = safeError(error);
    }
  }
  for (const query of queries) {
    if (manualLoginRequired) break;
    let result;
    try {
      if (authError) throw new Error(authError);
      result = await collectKeyword(page, query, identity);
    } catch (error) {
      if (manualLogin && page && new URL(page.url()).hostname === 'sso.snapppay.ir') {
        manualLoginRequired = true;
        break;
      }
      result = { query, sourceValid: false, sourceWarning: safeError(error) };
    }
    if (result.sourceValid) validCount++;
    else invalidCount++;
    await wp('/wp-json/sarmo-monitor/v1/observations', {
      method: 'POST',
      body: JSON.stringify(observationPayload(query, result)),
    });
    console.log(`${new Date().toISOString()} ${query}: ${result.sourceValid ? `grid ${result.gridRank ?? 'absent'}; ad slot ${result.absoluteAdSlot ?? 'absent'}` : 'invalid source'}`);
  }
} finally {
  await browser.close();
}
if (manualLoginRequired) {
  await reportLogin('required');
  console.log('Codespace login required; this run could not complete collection.');
  process.exitCode = 2;
} else if (manualLogin && validCount > 0) {
  await reportLogin('scanned');
}
console.log(`Collector summary: ${validCount} valid, ${invalidCount} invalid, ${queries.length} keywords`);
if (invalidCount > 0) process.exitCode = 1;
