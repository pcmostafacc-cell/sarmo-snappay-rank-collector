import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { scopedSession } from './session-state.mjs';

test('only SnappPay HTTPS origins and exact-domain cookies can be relayed', () => {
  const valid = { domain: '.snapppay.ir' };
  const rejected = [
    { domain: 'other.snapppay.ir' }, { domain: 'snapppay.ir.example' }, { domain: '.github.dev' },
  ];
  const origin = { origin: 'https://app.snapppay.ir' };
  assert.deepEqual(scopedSession({
    cookies: [valid, ...rejected],
    origins: [origin, { origin: 'http://sso.snapppay.ir' }, { origin: 'https://github.com' }],
  }), { cookies: [valid], origins: [origin] });
});

test('Ads and market sessions retain different application hosts', () => {
  const cookies = [{ domain: 'ads.snapppay.ir' }, { domain: 'app.snapppay.ir' }];
  const state = { cookies, origins: [] };
  assert.deepEqual(scopedSession(state, 'ads').cookies, [cookies[0]]);
  assert.deepEqual(scopedSession(state, 'market').cookies, [cookies[1]]);
});
