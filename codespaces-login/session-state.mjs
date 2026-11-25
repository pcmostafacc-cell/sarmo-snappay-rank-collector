export function scopedSession(state, scope = 'market') {
  const site = scope === 'ads' ? 'ads.snapppay.ir' : 'app.snapppay.ir';
  const appHosts = new Set([site, 'sso.snapppay.ir', 'snapppay.ir']);
  return {
    cookies: state.cookies.filter(cookie => appHosts.has(cookie.domain.replace(/^\./, ''))),
    origins: state.origins.filter(item => {
      const url = new URL(item.origin);
      return url.protocol === 'https:' && appHosts.has(url.hostname);
    }),
  };
}
