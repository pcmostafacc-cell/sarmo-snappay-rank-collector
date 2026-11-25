const statusLine = document.querySelector('#status');
const desktop = document.querySelector('#desktop');
const messages = {
  idle: 'از لینک تازهٔ پنل وردپرس وارد شو.',
  working: 'در پنجرهٔ زیر ورود به حساب اسنپ‌پی را کامل کن. اطلاعات ورود را همان‌جا وارد کن.',
  saved: 'نشست به وردپرس رسید. نتیجهٔ خواندن داده در اجرای بعدی در بله اعلام می‌شود.',
  'handoff-failed': 'وردپرس نشست را نپذیرفت. برای تلاش تازه از پنل وردپرس شروع کن.',
  'provider-limited': 'اسنپ‌پی ورود را با محدودیت ۴۲۹ متوقف کرد. بعد از رفع محدودیت دوباره تلاش کن.',
  'provider-unavailable': 'صفحهٔ رسمی اسنپ‌پی در Chrome خطای سرور داد. وقتی پنل دوباره در دسترس شد، لینک تازه بگیر.',
  'window-closed': 'پنجرهٔ ورود Chrome بسته شد. برای تلاش تازه از پنل وردپرس شروع کن.',
  'browser-failed': 'Chrome برای ورود باز نشد. نصب و دسترسی Chrome را بررسی کن.',
  'checking-session': 'ورود انجام شد. برنامه دارد نشست را در یک مرورگر جداگانه بررسی می‌کند…',
  'session-read-failed': 'Chrome نشست ورود را برای بررسی در اختیار برنامه نگذاشت.',
  'session-missing': 'پنل باز شد، اما دادهٔ ورود قابل انتقال پیدا نشد.',
  'session-not-portable': 'پنل در پنجرهٔ اول باز شد، اما نشست در مرورگر جداگانه معتبر نبود.',
  expired: 'زمان این تلاش تمام شد. برای تلاش تازه از پنل وردپرس شروع کن.',
  failed: 'مرورگر نتوانست ورود را کامل کند. وضعیت اسنپ‌پی و Codespace را بررسی کن.',
};
async function refresh() {
  try {
    const response = await fetch('/status', { cache: 'no-store' });
    const { state, viewer } = await response.json();
    statusLine.textContent = state === 'working' && !viewer
      ? 'ورود را در پنجرهٔ Chrome که باز شد کامل کن.' : messages[state] || messages.failed;
    const showDesktop = state === 'working' && viewer;
    if (showDesktop && !desktop.hasAttribute('src')) {
      desktop.src = '/vnc/vnc.html?autoconnect=1&reconnect=1&resize=scale&path=vnc%2Fwebsockify';
    }
    desktop.hidden = !showDesktop;
    if (desktop.hidden) desktop.removeAttribute('src');
  } catch {
    statusLine.textContent = 'اتصال به محیط ورود قطع شد.';
  }
}
const grant = new URLSearchParams(location.hash.slice(1)).get('grant');
async function start() {
  if (!grant) { await refresh(); return true; }
  history.replaceState(null, '', location.pathname);
  try {
    const response = await fetch('/pair', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ grant }),
    });
    if (response.ok) { await refresh(); return true; }
    statusLine.textContent = 'این لینک ورود معتبر نیست یا منقضی شده. از پنل وردپرس لینک تازه بگیر.';
  } catch {
    statusLine.textContent = 'اتصال به محیط ورود قطع شد.';
  }
  return false;
}
start().then(paired => { if (paired) setInterval(refresh, 2000); });
