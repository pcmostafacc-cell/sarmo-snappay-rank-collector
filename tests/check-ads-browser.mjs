import { chromium } from 'playwright';
import { strict as assert } from 'node:assert';
import { collectAdsSnapshot } from '../ads-panel-reader.mjs';

const active = ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'];
const other = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
const listHeader = ['نام کمپین', 'وضعیت'];
const detailHeader = ['وضعیت', 'قیمت پیشنهادی', 'بودجه مصرفی (تومان)', 'بودجه مصرفی امروز (تومان)',
  'تعداد کلیک', 'تعداد بازدید', 'تعداد محصولات', 'متوسط هزینه تمام‌شده هر کلیک'];
const table = (heads, rows) =>
  '<table><thead><tr>' + heads.map(h => '<th>' + h + '</th>').join('') + '</tr></thead><tbody>' + rows + '</tbody></table>';
const row = (id, status) => '<tr><td><a href="/campaign/' + id + '">تست</a></td><td>' + status + '</td></tr>';
const detail = table(detailHeader, '<tr>' +
  ['فعال', '۱۰٬۰۰۰', '۴۰٬۰۰۰از ۹۰٬۰۰۰', '۵٬۰۰۰از ۱۰٬۰۰۰', '۴', '۶۰', '۳', '۱۰٬۰۰۰']
    .map(cell => '<td>' + cell + '</td>').join('') + '</tr>');
const overview = '<p>موجودی : ۱۰۰ تومان</p><main id="root"></main><script>' +
  'let p=0; const render = () => document.querySelector("#root").innerHTML = ' +
  JSON.stringify(table(['نام کمپین', 'وضعیت<button aria-label="filter">filter</button>'],
    row(other, 'عدم اجازه فعالیت'))) + '; render(); ' +
  'const html = [' + active.map((id, i) => JSON.stringify(table(listHeader, row(id, 'فعال')) +
    '<ul><li aria-label="صفحه بعدی"><button aria-label="left" ' + (i + 1 < active.length ? '' : 'disabled') + '>next</button></li></ul>')).join(',') + '];' +
  'document.body.onclick = e => { const root=document.querySelector("#root"); if(e.target.ariaLabel==="filter"){' +
  'root.insertAdjacentHTML("beforeend", \'<div role="menu"><button role="menuitem">فعال</button></div><button>تایید</button>\');' +
  '} if(e.target.textContent==="تایید"){root.innerHTML=html[p];} if(e.target.ariaLabel==="left"){root.innerHTML=html[++p];} };' +
  '</script>';

const browser = await chromium.launch();
try {
  const context = await browser.newContext();
  await context.route('https://ads.snapppay.ir/**', route => {
    const path = new URL(route.request().url()).pathname;
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: path === '/' ? overview : detail });
  });
  const result = await collectAdsSnapshot(context);
  assert.equal(result.complete, true);
  assert.deepEqual(result.campaigns.map(c => c.id), active);
  assert.equal(result.campaigns[0].bid_toman, 10000);
  assert.equal(result.campaigns[0].daily_limit_toman, 10000);
  assert.equal(result.campaigns[1].views, 60);
  console.log('Ads fixture checked: active filter, pagination, CPC and budgets.');
} finally { await browser.close(); }
