import { chromium } from 'playwright';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { setTimeout as sleep } from 'node:timers/promises';
import { scopedSession } from './session-state.mjs';

const local = process.argv.includes('--local');
const origin = local ? 'http://127.0.0.1:6081' : process.env.SARMO_LOGIN_ORIGIN;
if (!local && (process.env.CODESPACES !== 'true' || !/^https:\/\/[a-z0-9-]+-6080\.app\.github\.dev$/.test(origin))) {
  throw new Error('Start the portal in a private Codespace or on loopback with --local.');
}
const wp = 'https://mehran.sarmostyle.com/wp-json/sarmo-monitor/v1/codespaces-session';
const search = 'https://app.snapppay.ir/search';
const assets = new Map([
  ['/', ['text/html; charset=utf-8', await readFile(new URL('./portal.html', import.meta.url))]],
  ['/portal.js', ['text/javascript; charset=utf-8', await readFile(new URL('./portal.js', import.meta.url))]],
]);
let state = 'idle';
let browserCookie = '';

function isPaired(req) {
  return browserCookie !== '' && (req.headers.cookie || '').split(';')
    .some(value => value.trim() === 'sarmo_login=' + browserCookie);
}

function postToWordPress(payload) {
  return fetch(wp, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    redirect: 'error',
    signal: AbortSignal.timeout(20000),
  });
}

async function receiveLogin(grant, scope) {
  const ads = scope === 'ads';
  const target = ads ? 'https://ads.snapppay.ir/' : search;
  const host = new URL(target).hostname;
  const ready = tab => ads
    ? tab.getByRole('heading', { name: 'کمپین‌های ساخته‌شده', exact: true })
    : tab.locator('[data-testid="search-typing-input"]');
  let browser;
  try {
    browser = await chromium.launch(local ? { headless: false, channel: 'chrome' } : {
      headless: false, args: ['--kiosk', '--window-size=1280,800'],
      env: { DISPLAY: process.env.DISPLAY, XAUTHORITY: process.env.XAUTHORITY, LANG: 'en_US.UTF-8', HOME: process.env.HOME },
    });
    const ctx = await browser.newContext({ locale: 'fa-IR', viewport: null });
    const page = await ctx.newPage();
    let sawLogin = false;
    page.on('framenavigated', frame => {
      if (frame === page.mainFrame() && new URL(frame.url()).hostname === 'sso.snapppay.ir') sawLogin = true;
    });
    await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const deadline = Date.now() + 10 * 60_000;
    while (Date.now() < deadline && !page.isClosed()) {
      if ((ads || sawLogin) && new URL(page.url()).hostname === host && await ready(page).isVisible()) {
        const probe = await ctx.newPage();
        try {
          await probe.goto(target, { waitUntil: 'domcontentloaded', timeout: 15000 });
          await ready(probe).waitFor({ timeout: 12000 });
          if (new URL(probe.url()).hostname === host) {
            const session = scopedSession(await ctx.storageState({ indexedDB: true }), scope);
            if (session.cookies.length === 0) throw new Error('No session cookies');
            const response = await postToWordPress({ grant, session });
            state = response.ok ? 'saved' : 'handoff-failed';
            return;
          }
        } finally {
          await probe.close().catch(() => {});
        }
      }
      await sleep(2000);
    }
    state = 'expired';
  } catch {
    state = 'failed';
  } finally {
    await browser?.close().catch(() => {});
  }
}

const server = createServer(async (req, res) => {
  const headers = {
    'Cache-Control': 'no-store',
    'Referrer-Policy': 'no-referrer',
    'X-Content-Type-Options': 'nosniff',
    'Content-Security-Policy': "default-src 'none'; script-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'",
  };
  const reply = (code, data, extra = {}) => res.writeHead(code, {
    ...headers, ...extra, 'Content-Type': 'application/json',
  }).end(JSON.stringify(data));
  if (local && req.headers.host !== '127.0.0.1:6081') return reply(403, { ok: false });
  if (req.method === 'GET' && assets.has(req.url)) {
    const [type, body] = assets.get(req.url);
    return res.writeHead(200, { ...headers, 'Content-Type': type }).end(body);
  }
  if (req.url === '/portal-check') {
    return reply(state === 'working' && isPaired(req) ? 200 : 403, {});
  }
  if (req.method === 'GET' && req.url === '/status') {
    return reply(200, { state: isPaired(req) ? state : 'idle', viewer: !local });
  }
  if (req.method !== 'POST' || req.url !== '/pair') return reply(404, { ok: false });
  if (req.headers.origin !== origin || !/^application\/json(?:;|$)/i.test(req.headers['content-type'] || '')) {
    return reply(403, { ok: false });
  }
  if (state === 'working') return reply(409, { ok: false });
  try {
    let data = '';
    for await (const chunk of req) {
      data += chunk;
      if (data.length > 200) return reply(413, { ok: false });
    }
    const { grant } = JSON.parse(data);
    if (typeof grant !== 'string' || !/^[a-f0-9]{64}$/.test(grant)) return reply(400, { ok: false });
    if (state === 'working') return reply(409, { ok: false });
    const check = await postToWordPress({ grant, check: true });
    if (!check.ok) return reply(403, { ok: false });
    const { scope } = await check.json();
    if (scope !== 'ads' && scope !== 'market') return reply(403, { ok: false });
    if (state === 'working') return reply(409, { ok: false });
    browserCookie = randomBytes(32).toString('hex');
    state = 'working';
    reply(202, { ok: true }, {
      'Set-Cookie': 'sarmo_login=' + browserCookie + '; Max-Age=720; Path=/; HttpOnly; SameSite=Strict' + (local ? '' : '; Secure'),
    });
    void receiveLogin(grant, scope);
  } catch {
    reply(400, { ok: false });
  }
});
server.listen(6081, '127.0.0.1', () => {
  if (local) console.log('Sarmo login is ready on loopback. Open the link in Bale on this device.');
});
