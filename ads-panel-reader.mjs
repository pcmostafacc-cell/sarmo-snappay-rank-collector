const root = new URL('https://ads.snapppay.ir/');
const headings = {
  status: 'وضعیت', name: 'نام کمپین', bid: 'قیمت پیشنهادی',
  lifetimeBudget: 'بودجه مصرفی (تومان)', dailyBudget: 'بودجه مصرفی امروز (تومان)',
  clicks: 'تعداد کلیک', views: 'تعداد بازدید', products: 'تعداد محصولات',
  actualCpc: 'متوسط هزینه تمام‌شده هر کلیک',
};

const normalize = text => String(text ?? '').replace(/[۰-۹]/g, x => '۰۱۲۳۴۵۶۷۸۹'.indexOf(x))
  .replace(/[٠-٩]/g, x => '٠١٢٣٤٥٦٧٨٩'.indexOf(x)).replace(/[٬,]/g, '').trim();

export function amount(text) {
  const match = normalize(text).match(/^(\d+)(?:\s*تومان)?$/u);
  const number = match ? Number(match[1]) : NaN;
  return Number.isSafeInteger(number) ? number : null;
}

export function budget(text) {
  const [spend, limit, extra] = normalize(text).split('از');
  if (extra !== undefined) return { spend: null, limit: null };
  return { spend: amount(spend), limit: amount(limit) };
}

function requireColumns(headers, columns) {
  const indexes = {};
  for (const column of columns) {
    const index = headers.indexOf(headings[column]);
    if (index < 0 || headers.lastIndexOf(headings[column]) !== index) throw new Error('Ads columns changed');
    indexes[column] = index;
  }
  return indexes;
}

async function visibleGrid(table) {
  return table.evaluate(node => ({
    headers: [...node.querySelectorAll('thead th')].map(th => th.textContent.trim()),
    rows: [...node.querySelectorAll('tbody tr')].filter(row => row.getClientRects().length > 0).map(tr => ({
      cells: [...tr.querySelectorAll('td')].map(cell => cell.textContent.trim()),
      campaign: tr.querySelector('td a[href^="/campaign/"]')?.getAttribute('href') || '',
      name: tr.querySelector('td a[href^="/campaign/"]')?.textContent.trim() || '',
    })),
  }));
}

export class AdsLoginRequired extends Error {}
const campaignId = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;

async function assertReachable(page, response) {
  const url = new URL(page.url());
  if (response?.status() === 401 || url.hostname === 'sso.snapppay.ir' ||
      (url.hostname === root.hostname && /\/login\/?$/.test(url.pathname)) ||
      await page.locator('input[type="password"]').first().isVisible()) {
    throw new AdsLoginRequired('Ads sign-in required');
  }
  if (url.hostname !== root.hostname || !response?.ok()) throw new Error('Ads panel not available');
}

async function details(context, row) {
  if (!campaignId.test(row.id)) throw new Error('Invalid campaign ID');
  const page = await context.newPage();
  try {
    const res = await page.goto(new URL('/campaign/' + row.id, root).href, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await assertReachable(page, res);
    const table = page.locator('table').filter({ has: page.getByRole('columnheader', { name: headings.actualCpc, exact: true }) });
    await table.waitFor({ timeout: 12000 });
    const data = await visibleGrid(table);
    const ix = requireColumns(data.headers, ['status', 'bid', 'lifetimeBudget', 'dailyBudget', 'clicks', 'views', 'products', 'actualCpc']);
    if (data.rows.length !== 1) throw new Error('Campaign detail layout changed');
    const cells = data.rows[0].cells;
    const daily = budget(cells[ix.dailyBudget]);
    const total = budget(cells[ix.lifetimeBudget]);
    return {
      ...row,
      active: cells[ix.status] === 'فعال',
      period: 'lifetime',
      bid_toman: amount(cells[ix.bid]),
      daily_spend_toman: daily.spend, daily_limit_toman: daily.limit,
      total_spend_toman: total.spend, total_limit_toman: total.limit,
      clicks: amount(cells[ix.clicks]), views: amount(cells[ix.views]),
      products: amount(cells[ix.products]), actual_cpc_toman: amount(cells[ix.actualCpc]),
    };
  } finally { await page.close().catch(() => {}); }
}

async function activeCampaigns(page, target) {
  const response = await page.goto(root.href, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await assertReachable(page, response);
  const table = page.locator('table').filter({ has: page.getByRole('columnheader', { name: headings.name, exact: true }) });
  await table.waitFor({ timeout: 15000 });
  const status = table.getByRole('columnheader').filter({ hasText: headings.status });
  await status.getByRole('button', { name: 'filter' }).click();
  await page.getByRole('menuitem', { name: 'فعال', exact: true }).click();
  await page.getByRole('button', { name: 'تایید', exact: true }).click();
  const campaigns = new Map();
  const next = page.getByRole('listitem', { name: 'صفحه بعدی', exact: true }).getByRole('button', { name: 'left', exact: true });
  for (let index = 0; index < 25; index++) {
    await page.waitForFunction(({ heading, ids }) => {
      const table = [...document.querySelectorAll('table')].find(t => [...t.querySelectorAll('th')].some(th => th.textContent.trim() === 'نام کمپین'));
      if (!table) return false;
      const column = [...table.querySelectorAll('th')].findIndex(th => th.textContent.trim() === heading);
      const rows = [...table.querySelectorAll('tbody tr')];
      if (table.querySelector('tbody .ant-empty-description')?.getClientRects().length) return true;
      const links = rows.map(row => row.querySelector('td a[href^="/campaign/"]')?.getAttribute('href')).filter(Boolean);
      return links.length > 0 && links.some(href => !ids.includes(href)) &&
        rows.every(row => row.querySelectorAll('td')[column]?.textContent.trim() === 'فعال');
    }, { heading: headings.status, ids: [...campaigns.keys()] }, { timeout: 15000 });
    const data = await visibleGrid(table);
    const ix = requireColumns(data.headers, ['status']);
    const empty = await table.locator('tbody .ant-empty-description').isVisible();
    if (empty && index === 0) break;
    for (const row of data.rows) {
      if (!row.campaign.startsWith('/campaign/') || !campaignId.test(row.campaign.slice(10)) ||
          !row.name || row.cells[ix.status] !== 'فعال' || campaigns.has(row.campaign)) {
        throw new Error('Ads active campaign page incomplete or duplicated');
      }
      campaigns.set(row.campaign, { id: row.campaign.slice('/campaign/'.length), name: row.name });
    }
    if (campaigns.has(target)) break;
    if (!await next.count() || !await next.isEnabled()) break;
    if (index === 24) throw new Error('Ads pagination exceeded the limit');
    await next.click();
  }
  return { table, campaigns };
}

export async function collectAdsSnapshot(context) {
  const page = await context.newPage();
  try {
    const { campaigns } = await activeCampaigns(page);
    const balanceText = (await page.getByText(/^موجودی\s*:/).first().textContent({ timeout: 2000 }).catch(() => '')) || '';
    const all = [];
    for (const row of campaigns.values()) all.push(await details(context, row));
    return {
      observed_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
      complete: true, balance_toman: amount(balanceText.replace(/^موجودی\s*:\s*/u, '')), campaigns: all,
    };
  } finally { await page.close().catch(() => {}); }
}

function hasRoom(spend, limit) {
  return Number.isSafeInteger(spend) && Number.isSafeInteger(limit) && spend >= 0 && limit > spend;
}

export async function applyApprovedBid(context, job, claim) {
  const target = { id: job.campaign_id };
  const oldBid = amount(job.old_bid);
  const newBid = amount(job.new_bid);
  const blocked = reason => ({ state: 'blocked', reason });
  if (oldBid === null || !newBid || newBid > 1_000_000_000 || oldBid === newBid || !campaignId.test(target.id)) {
    return blocked('guard');
  }
  const overview = await context.newPage();
  let submitted = false;
  try {
    const detail = await details(context, target);
    if (!detail.active) return blocked('inactive');
    if (detail.bid_toman !== oldBid) return blocked('stale_bid');
    // Pausing, a spent budget or ambiguous amounts must stop the write.
    if (!hasRoom(detail.daily_spend_toman, detail.daily_limit_toman) ||
        !hasRoom(detail.total_spend_toman, detail.total_limit_toman)) return blocked('guard');

    const path = '/campaign/' + target.id;
    const { table, campaigns } = await activeCampaigns(overview, path);
    if (!campaigns.has(path)) return blocked('inactive');
    const grid = await visibleGrid(table);
    const columns = requireColumns(grid.headers, ['status', 'bid']);
    const row = table.getByRole('row').filter({ has: overview.locator(`a[href="${path}"]`) });
    if (await row.count() !== 1) return blocked('editor');
    const cells = row.getByRole('cell');
    if ((await cells.nth(columns.status).innerText({ timeout: 1500 })).trim() !== 'فعال') return blocked('inactive');
    const offer = cells.nth(columns.bid);
    if (amount(await offer.innerText({ timeout: 1500 })) !== oldBid) return blocked('stale_bid');
    await offer.getByRole('button').click({ timeout: 3500 });
    const input = overview.getByRole('textbox', { name: 'مقدار دلخواه خود را وارد کنید', exact: true });
    const apply = overview.getByRole('button', { name: 'اعمال تغییرات', exact: true });
    if (amount(await input.inputValue({ timeout: 3500 })) !== oldBid) return blocked('stale_bid');
    await input.fill(String(newBid), { timeout: 3500 });
    if (amount(await input.inputValue({ timeout: 1500 })) !== newBid) return blocked('editor');
    await apply.click({ trial: true, timeout: 3500 });
    const expires = await claim();
    if (!expires || expires * 1000 <= Date.now()) return blocked('guard');

    submitted = true;
    await apply.click({ timeout: Math.min(3500, expires * 1000 - Date.now()) });
    for (const wait of [0, 1000]) {
      if (wait) await new Promise(resolve => setTimeout(resolve, wait));
      const observed = await details(context, target).catch(() => null);
      if (observed?.bid_toman === newBid) return { state: 'applied', actual_bid: newBid };
    }
    return { state: 'uncertain', reason: 'readback' };
  } catch (error) {
    if (!submitted && error instanceof AdsLoginRequired) throw error;
    return submitted ? { state: 'uncertain', reason: 'readback' } : blocked('editor');
  } finally { await overview.close().catch(() => {}); }
}
