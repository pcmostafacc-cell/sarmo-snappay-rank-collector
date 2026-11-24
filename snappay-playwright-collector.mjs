// Run in a dedicated Playwright worker. n8n should call the worker only after
// the worker has authenticated; never pass OTPs or session cookies in results.
import { productKey, summarizeRank } from './rank-core.mjs';

const SEARCH_URL = 'https://app.snapppay.ir/search';

export async function collectKeyword(page, query, identity, maxProducts = 120) {
  await page.goto(SEARCH_URL, { waitUntil: 'domcontentloaded' });
  const input = page.getByRole('searchbox');
  await input.fill(query);
  await input.press('Enter');
  await page.locator('[data-testid="search-result-input"]').waitFor({ timeout: 20000 });
  await page.locator('[data-testid="product-item"]').first().waitFor({ timeout: 20000 });
  await page.waitForTimeout(1000);

  const seen = new Map();
  let sequenceReliable = true;
  let scanComplete = false;
  let previous = new Set();
  let bottomStable = 0;
  for (let pass = 0; pass < 80 && seen.size < maxProducts; pass++) {
    const frame = await page.evaluate(() => {
      const cards = [...document.querySelectorAll('[data-testid="product-item"]')];
      const grid = cards[0] && (() => {
        let element = cards[0].parentElement;
        while (element && element !== document.body) {
          if (element.scrollHeight > element.clientHeight + 80 &&
              element.clientHeight > 100) return element;
          element = element.parentElement;
        }
        return null;
      })();
      const scroll = grid || document.scrollingElement;
      const canScroll = !!scroll && scroll.scrollHeight > scroll.clientHeight + 80;
      return {
        products: cards.map(card => ({
          merchant: card.querySelector('[data-testid="merchant-name"]')?.textContent?.trim() || '',
          title: card.querySelector('[data-testid="product-name"]')?.textContent?.trim() || '',
          image: card.querySelector('img[src]')?.getAttribute('src') || '',
          isAd: !!card.querySelector('[data-testid="ads-badge"]'),
        })),
        atBottom: !canScroll || scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 5,
        canScroll,
      };
    });
    if (!frame.products.length) break;
    const keys = new Set(frame.products.map(productKey));
    if (pass > 0 && ![...keys].some(key => previous.has(key))) sequenceReliable = false;
    const sizeBefore = seen.size;
    for (const product of frame.products) seen.set(productKey(product), product);
    previous = keys;
    if (frame.atBottom && seen.size === sizeBefore) bottomStable++;
    else bottomStable = 0;
    if (bottomStable >= 3) {
      scanComplete = true;
      break;
    }
    await page.evaluate(() => {
      const card = document.querySelector('[data-testid="product-item"]');
      let element = card?.parentElement;
      while (element && element !== document.body) {
        if (element.scrollHeight > element.clientHeight + 80 &&
            element.clientHeight > 100) {
          element.scrollTop += Math.max(180, Math.floor(element.clientHeight * 0.4));
          return;
        }
        element = element.parentElement;
      }
      const root = document.scrollingElement;
      if (root) root.scrollTop += Math.max(180, Math.floor(root.clientHeight * 0.4));
    });
    await page.waitForTimeout(frame.atBottom ? 600 : 300);
  }
  const header = await page.evaluate(() => ({
    displayedQuery: (() => {
      const field = document.querySelector('[data-testid="search-result-input"]');
      return (field?.value || field?.textContent || '').trim();
    })(),
    merchants: [...document.querySelectorAll('[data-testid="merchant-result"]')]
      .map(el => el.querySelector('[data-testid="merchant-result-action"]')?.textContent?.trim() || ''),
    banners: [...document.querySelectorAll('[data-testid="banner-item"]')]
      .map(el => el.querySelector('p')?.textContent?.trim() || ''),
    sourceWarning: [...document.querySelectorAll('[role="alert"]')]
      .map(el => el.textContent?.trim()).filter(Boolean).join(' | ') ||
      (document.body.innerText.includes('فیلتر اعمال نشد') ? 'خطای اعمال فیلتر' : null),
  }));
  return summarizeRank({
    query, observedAt: new Date().toISOString(), products: [...seen.values()],
    sequenceReliable, scanComplete, ...header,
  }, identity);
}
