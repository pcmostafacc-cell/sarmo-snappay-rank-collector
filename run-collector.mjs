import { chromium } from 'playwright';
import { collectKeyword } from './snappay-playwright-collector.mjs';
import { openSnappPay } from './snappay-auth.mjs';

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

const keywords = await wp('/wp-json/sarmo-monitor/v1/keywords');
const queries = Object.keys(keywords);
if (!queries.length) process.exit(0);

const browser = await chromium.launch({ headless: true });
try {
  let page;
  let authError = null;
  try {
    ({ page } = await openSnappPay(browser, wp));
  } catch (error) {
    authError = String(error).slice(0, 300);
  }
  for (const query of queries) {
    let result;
    try {
      if (authError) throw new Error(authError);
      result = await collectKeyword(page, query, { merchantAliases: ['سارمو استایل'] });
    } catch (error) {
      result = { query, sourceValid: false, sourceWarning: String(error).slice(0, 300) };
    }
    await wp('/wp-json/sarmo-monitor/v1/observations', {
      method: 'POST',
      body: JSON.stringify({
        query,
        source_valid: result.sourceValid,
        source_warning: result.sourceWarning || '',
        seller: result.ownProducts?.[0]?.merchant || '',
        grid_rank: result.gridRank,
        ad_rank: result.adRank,
        merchant_rank: result.merchantCarouselRank,
        scanned_products: result.scannedProducts || 0,
        scan_complete: result.scanComplete || false,
        details: {
          own_products: result.ownProducts || [],
          top_competitors: result.topCompetitors || [],
          scan_complete: result.scanComplete || false,
        },
      }),
    });
    console.log(`${new Date().toISOString()} ${query}: ${result.sourceValid ? `rank ${result.gridRank ?? 'absent'}` : 'invalid source'}`);
  }
} finally {
  await browser.close();
}
