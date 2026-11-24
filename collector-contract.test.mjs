import assert from 'node:assert/strict';
import { test } from 'node:test';
import { assertCollectorContract } from './collector-contract.mjs';

test('collector refuses to write observations against an older WordPress plugin', () => {
  assert.throws(() => assertCollectorContract({ api_version: '0.5.1' }), /API mismatch/);
  assert.throws(() => assertCollectorContract(null), /API mismatch/);
});

test('collector accepts only the matching OTP-safe API contract', () => {
  assert.doesNotThrow(() => assertCollectorContract({
    api_version: '0.6.0', otp_mode: 'owner-armed-one-time',
  }));
  assert.throws(() => assertCollectorContract({
    api_version: '0.6.0', otp_mode: 'automatic',
  }), /API mismatch/);
});
