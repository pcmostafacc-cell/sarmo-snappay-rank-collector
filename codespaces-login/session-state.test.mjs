import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { hasSessionState, scopedSession } from './session-state.mjs';

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

test('Ads supports a storage-only session from its own HTTPS origin', () => {
  const ads = { origin: 'https://ads.snapppay.ir', localStorage: [{ name: 'fixture', value: 'sample' }] };
  const market = { ...ads, origin: 'https://app.snapppay.ir' };
  const state = { cookies: [], origins: [ads, market] };
  assert.equal(hasSessionState(scopedSession(state, 'ads'), 'ads'), true);
  assert.equal(hasSessionState(scopedSession(state, 'market'), 'market'), false);
  assert.equal(hasSessionState({ cookies: [], origins: [market] }, 'ads'), false);
  assert.equal(hasSessionState({ cookies: [], origins: [{ ...ads, localStorage: [] }] }, 'ads'), false);
});
