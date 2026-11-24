const appHosts = new Set(['app.snapppay.ir', 'sso.snapppay.ir', 'snapppay.ir']);

export function scopedSession(state) {
  return {
    cookies: state.cookies.filter(cookie => appHosts.has(cookie.domain.replace(/^\./, ''))),
    origins: state.origins.filter(item => {
      const url = new URL(item.origin);
      return url.protocol === 'https:' && appHosts.has(url.hostname);
    }),
  };
}
