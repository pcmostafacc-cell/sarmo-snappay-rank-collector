const statusLine = document.querySelector('#status');
const desktop = document.querySelector('#desktop');
const messages = {
  idle: 'از لینک تازهٔ پنل وردپرس وارد شو.',
  working: 'در پنجرهٔ زیر مستقیم وارد اسنپ‌پی شو. از همان پنجره برای واردکردن کد پیامک استفاده کن.',
  saved: 'نشست به وردپرس رسید. نتیجهٔ خواندن محصولات در اجرای بعدی بررسی می‌شود.',
  'handoff-failed': 'وردپرس نشست را نپذیرفت. برای تلاش تازه از پنل وردپرس شروع کن.',
  expired: 'زمان این تلاش تمام شد. برای تلاش تازه از پنل وردپرس شروع کن.',
  failed: 'مرورگر نتوانست ورود را کامل کند. وضعیت اسنپ‌پی و Codespace را بررسی کن.',
};
async function refresh() {
  try {
    const response = await fetch('/status', { cache: 'no-store' });
    const { state } = await response.json();
    statusLine.textContent = messages[state] || messages.failed;
    if (state === 'working' && !desktop.hasAttribute('src')) {
      desktop.src = '/vnc/vnc.html?autoconnect=1&reconnect=1&resize=scale&path=vnc%2Fwebsockify';
    }
    desktop.hidden = state !== 'working';
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
