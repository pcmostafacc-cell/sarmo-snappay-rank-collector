import assert from 'node:assert/strict';
import { test } from 'node:test';
import { assertLoginArmed, claimOneTimeLogin, isOtpRateLimited, isProviderRateLimited } from './snappay-auth.mjs';

const testPhone = `09${'1'.repeat(9)}`;

test('OTP rate-limit detection recognizes HTTP 429 and Persian provider messages', () => {
  assert.equal(isOtpRateLimited(['429 sso.snapppay.ir']), true);
  assert.equal(isOtpRateLimited([], ['تعداد درخواست کد بیش از حد مجاز است']), true);
  assert.equal(isOtpRateLimited(['503 sso.snapppay.ir'], ['صفحه بارگذاری نشد']), false);
});

test('active provider lock can be checked before installing or launching a browser', () => {
  assert.equal(isProviderRateLimited({ rate_limited_until: 1700000060 }, 1700000000000), true);
  assert.equal(isProviderRateLimited({ rate_limited_until: 1700000000 }, 1700000000000), false);
  assert.equal(isProviderRateLimited({}, 1700000000000), false);
});

test('scheduled run cannot claim OTP when Bale login is not armed', async () => {
  let calls = 0;
  assert.throws(() => assertLoginArmed({ login_armed: false }), /authorize one OTP attempt/);
  await assert.rejects(() => claimOneTimeLogin(async () => { calls++; }, {}), /authorize one OTP attempt/);
  assert.equal(calls, 0);
});

test('provider rate limit fails closed before trying to claim an OTP', async () => {
  let calls = 0;
  assert.throws(() => assertLoginArmed({
    login_armed: false, rate_limited_until: Math.floor(Date.now() / 1000) + 3600,
  }), /provider rate limit is active/);
  await assert.rejects(() => claimOneTimeLogin(async () => { calls++; }, {
    login_armed: false, rate_limited_until: Math.floor(Date.now() / 1000) + 3600,
  }), /provider rate limit is active/);
  assert.equal(calls, 0);
});

test('armed login claims one server authorization and returns only its phone', async () => {
  let calls = 0;
  const phone = await claimOneTimeLogin(async (path, options) => {
    calls++;
    assert.equal(path, '/wp-json/sarmo-monitor/v1/collector-auth');
    assert.deepEqual(JSON.parse(options.body), { action: 'claim_login_attempt' });
    return { phone: testPhone };
  }, { login_armed: true });
  assert.equal(phone, testPhone);
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
