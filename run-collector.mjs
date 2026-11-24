import { chromium } from 'playwright';
import { collectKeyword } from './snappay-playwright-collector.mjs';
import { openSnappPay } from './snappay-auth.mjs';
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
  });
  if (!response.ok) throw new Error(`WordPress ${path}: HTTP ${response.status}`);
  return response.json();
}

const status = await wp('/wp-json/sarmo-monitor/v1/collector-status');
assertCollectorContract(status);

const keywords = await wp('/wp-json/sarmo-monitor/v1/keywords');
const queries = Object.keys(keywords);
if (!queries.length) process.exit(0);
if (queries.length > 10) throw new Error('Monitoring is capped at 10 keywords to control runner usage');

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
try {
  let page;
  let authError = null;
  try {
    ({ page } = await openSnappPay(browser, wp));
  } catch (error) {
    authError = safeError(error);
  }
  for (const query of queries) {
    let result;
    try {
      if (authError) throw new Error(authError);
      result = await collectKeyword(page, query, identity);
    } catch (error) {
      result = { query, sourceValid: false, sourceWarning: safeError(error) };
    }
    if (result.sourceValid) validCount++;
    else invalidCount++;
    await wp('/wp-json/sarmo-monitor/v1/observations', {
      method: 'POST',
      body: JSON.stringify({
        query,
        source_valid: result.sourceValid,
        source_warning: result.sourceWarning || '',
        seller: result.ownProducts?.length ? 'سارمو استایل' : '',
        grid_rank: result.gridRank,
        ad_rank: result.adRank,
        absolute_ad_slot: result.absoluteAdSlot,
        merchant_rank: result.merchantCarouselRank,
        scanned_products: result.scannedProducts || 0,
        scan_complete: result.scanComplete || false,
        details: {
          own_products: result.ownProducts || [],
          top_competitors: result.topCompetitors || [],
          scan_complete: result.scanComplete || false,
          rank_scope: result.rankScope || '',
        },
      }),
    });
    console.log(`${new Date().toISOString()} ${query}: ${result.sourceValid ? `grid ${result.gridRank ?? 'absent'}; ad slot ${result.absoluteAdSlot ?? 'absent'}` : 'invalid source'}`);
  }
} finally {
  await browser.close();
}
console.log(`Collector summary: ${validCount} valid, ${invalidCount} invalid, ${queries.length} keywords`);
if (invalidCount > 0) process.exitCode = 1;
