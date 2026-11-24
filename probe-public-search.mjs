import { chromium } from 'playwright';
import { collectKeyword } from './snappay-playwright-collector.mjs';

const query = 'عبا';
const browser = await chromium.launch();
try {
  const page = await browser.newPage({ locale: 'fa-IR' });
  const response = await page.goto('https://app.snapppay.ir/search', {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });
  if (new URL(page.url()).hostname === 'sso.snapppay.ir') {
    console.log('Guest search redirected to the official login page.');
    process.exitCode = 2;
  } else if (!response?.ok()) {
    console.error('Guest search returned HTTP', response?.status() ?? 'unknown');
    process.exitCode = 1;
  } else {
    try {
      const result = await collectKeyword(page, query, {
        merchantAliases: ['سارمو استایل'], productIds: [], sellerIds: [],
      }, 30);
      console.log(JSON.stringify({
        query,
        sourceValid: result.sourceValid,
        cards: result.scannedProducts,
        sarmoCards: result.ownProducts?.length ?? 0,
        gridRank: result.gridRank,
      }));
      if (!result.sourceValid) process.exitCode = 1;
    } catch {
      console.error(new URL(page.url()).hostname === 'sso.snapppay.ir'
        ? 'Guest search reached the official login page.'
        : 'Guest search did not expose verifiable results.');
      process.exitCode = 1;
    }
  }
} finally {
  await browser.close();
}
