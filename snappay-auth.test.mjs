import assert from 'node:assert/strict';
import { test } from 'node:test';
import { assertLoginArmed, claimOneTimeLogin } from './snappay-auth.mjs';

test('scheduled run cannot claim OTP when Bale login is not armed', async () => {
  let calls = 0;
  assert.throws(() => assertLoginArmed({ login_armed: false }), /authorize one OTP attempt/);
  await assert.rejects(() => claimOneTimeLogin(async () => { calls++; }, {}), /authorize one OTP attempt/);
  assert.equal(calls, 0);
});

test('armed login claims one server authorization and returns only its phone', async () => {
  let calls = 0;
  const phone = await claimOneTimeLogin(async (path, options) => {
    calls++;
    assert.equal(path, '/wp-json/sarmo-monitor/v1/collector-auth');
    assert.deepEqual(JSON.parse(options.body), { action: 'claim_login_attempt' });
    return { phone: '09123456789' };
  }, { login_armed: true });
  assert.equal(phone, '09123456789');
  assert.equal(calls, 1);
});

test('claim without a valid phone fails closed and aborts the challenge', async () => {
  const actions = [];
  await assert.rejects(() => claimOneTimeLogin(async (_path, options) => {
    const action = JSON.parse(options.body).action;
    actions.push(action);
    return action === 'claim_login_attempt' ? { phone: '' } : { ok: true };
  }, { login_armed: true }), /no valid phone/);
  assert.deepEqual(actions, ['claim_login_attempt', 'abort_challenge']);
});
