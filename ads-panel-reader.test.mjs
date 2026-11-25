import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { amount, budget } from './ads-panel-reader.mjs';

test('Ads amounts distinguish zero from missing data and accept Persian digits', () => {
  assert.equal(amount('۰'), 0);
  assert.equal(amount('۱۲٬۰۰۰ تومان'), 12000);
  assert.equal(amount('۳٫۵'), null);
  assert.equal(amount('—'), null);
  assert.equal(amount(''), null);
  assert.deepEqual(budget('۲۰٬۰۰۰از ۵٬۰۰۰٬۰۰۰'), { spend: 20000, limit: 5000000 });
  assert.deepEqual(budget('—'), { spend: null, limit: null });
});
