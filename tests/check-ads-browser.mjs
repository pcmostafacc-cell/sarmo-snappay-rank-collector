import { chromium } from 'playwright';
import { strict as assert } from 'node:assert';
import { applyApprovedBid, collectAdsSnapshot } from '../ads-panel-reader.mjs';

const active = ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'];
const other = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
const listHeader = ['نام کمپین', 'وضعیت', 'قیمت پیشنهادی'];
const detailHeader = ['وضعیت', 'قیمت پیشنهادی', 'بودجه مصرفی (تومان)', 'بودجه مصرفی امروز (تومان)',
  'تعداد کلیک', 'تعداد بازدید', 'تعداد محصولات', 'متوسط هزینه تمام‌شده هر کلیک'];
const table = (heads, rows) =>
  '<table><thead><tr>' + heads.map(h => '<th>' + h + '</th>').join('') + '</tr></thead><tbody>' + rows + '</tbody></table>';
const row = (id, status) => '<tr><td><a href="/campaign/' + id + '">تست</a></td><td>' + status +
  '</td><td><button class="offer">۱۰٬۰۰۰</button></td></tr>';
const detail = (status, bid) => table(detailHeader, '<tr>' +
  [status, bid, '۴۰٬۰۰۰از ۹۰٬۰۰۰', '۵٬۰۰۰از ۱۰٬۰۰۰', '۴', '۶۰', '۳', '۱۰٬۰۰۰']
    .map(cell => '<td>' + cell + '</td>').join('') + '</tr>');
const overview = '<p>موجودی : ۱۰۰ تومان</p><main id="root"></main><script>' +
  'let p=0; const render = () => document.querySelector("#root").innerHTML = ' +
  JSON.stringify(table(['نام کمپین', 'وضعیت<button aria-label="filter">filter</button>'],
    row(other, 'عدم اجازه فعالیت'))) + '; render(); ' +
  'const html = [' + active.map((id, i) => JSON.stringify(table(listHeader, row(id, 'فعال')) +
    '<ul><li aria-label="صفحه بعدی"><button aria-label="left" ' + (i + 1 < active.length ? '' : 'disabled') + '>next</button></li></ul>')).join(',') + '];' +
  'document.body.onclick = e => { const root=document.querySelector("#root"); if(e.target.ariaLabel==="filter"){' +
  'root.insertAdjacentHTML("beforeend", \'<div role="menu"><button role="menuitem">فعال</button></div><button>تایید</button>\');' +
  '} if(e.target.textContent==="تایید"){root.innerHTML=html[p];} if(e.target.ariaLabel==="left"){root.innerHTML=html[++p];}' +
  'if(e.target.className==="offer"){root.insertAdjacentHTML("beforeend", \'<input aria-label="مقدار دلخواه خود را وارد کنید" value="10,000"><button>اعمال تغییرات</button>\');}' +
  'if(e.target.textContent==="اعمال تغییرات"){fetch("/test-change", {method:"POST",body:document.querySelector("input").value});} };' +
  '</script>';

const browser = await chromium.launch();
try {
  const context = await browser.newContext();
  let liveStatus = 'فعال';
  let current = 10000;
  let writes = 0;
  let claimCount = 0;
  const claim = async () => { claimCount++; return Date.now() / 1000 + 60; };
  await context.route('https://ads.snapppay.ir/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/test-change') {
      writes++;
      assert.equal(claimCount, 1);
      current = Number(route.request().postData());
      return route.fulfill({ status: 204 });
    }
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: path === '/' ? overview : detail(liveStatus, current) });
  });
  const result = await collectAdsSnapshot(context);
  assert.equal(result.complete, true);
  assert.deepEqual(result.campaigns.map(c => c.id), active);
  assert.equal(result.campaigns[0].bid_toman, 10000);
  assert.equal(result.campaigns[0].daily_limit_toman, 10000);
  assert.equal(result.campaigns[1].views, 60);
  console.log('Ads fixture checked: active filter, pagination, CPC and budgets.');

  const job = { campaign_id: active[1], old_bid: 10000, new_bid: 15000 };
  liveStatus = 'متوقف';
  assert.deepEqual(await applyApprovedBid(context, job, claim), { state: 'blocked', reason: 'inactive' });
  liveStatus = 'فعال';
  assert.deepEqual(await applyApprovedBid(context, { ...job, old_bid: 9000 }, claim), { state: 'blocked', reason: 'stale_bid' });
  assert.deepEqual(await applyApprovedBid(context, job, async () => null), { state: 'blocked', reason: 'guard' });
  assert.equal(writes, 0);
  assert.equal(claimCount, 0);
  assert.deepEqual(await applyApprovedBid(context, job, claim), { state: 'applied', actual_bid: 15000 });
  assert.equal(writes, 1);
  assert.deepEqual(await applyApprovedBid(context, job, claim), { state: 'blocked', reason: 'stale_bid' });
  assert.equal(writes, 1);
  assert.equal(claimCount, 1);
  console.log('Ads bid fixture checked: inactive, stale, unclaimed and replayed jobs cannot write, approved amount is verified.');
} finally { await browser.close(); }
