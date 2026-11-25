const statusLine = document.querySelector('#status');
const desktop = document.querySelector('#desktop');
const messages = {
  idle: 'از لینک تازهٔ پنل وردپرس وارد شو.',
  working: 'در پنجرهٔ زیر ورود به حساب اسنپ‌پی را کامل کن. اطلاعات ورود را همان‌جا وارد کن.',
  saved: 'نشست به وردپرس رسید. نتیجهٔ خواندن داده در اجرای بعدی در بله اعلام می‌شود.',
  'handoff-failed': 'وردپرس نشست را نپذیرفت. برای تلاش تازه از پنل وردپرس شروع کن.',
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
if (grant) {
  history.replaceState(null, '', location.pathname);
  fetch('/pair', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ grant }),
  }).then(refresh, refresh);
} else {
  refresh();
}
setInterval(refresh, 2000);
