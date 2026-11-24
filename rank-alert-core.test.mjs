import assert from 'node:assert/strict';
import { updateRankTarget } from './rank-alert-core.mjs';

const options = { targetRank: 1, rankField: 'adRank' };
let state = {};
function feed(observedAt, adRank, sourceValid = true) {
  const result = updateRankTarget(state, {
    observedAt, adRank, sourceValid, scanComplete: true,
  }, options);
  state = result.state;
  return result.event;
}
assert.equal(feed('1', 1), null);
assert.equal(feed('2', 1), 'target_met');
assert.equal(feed('3', 2), null);
assert.equal(feed('4', null, false), null);
assert.equal(feed('5', 2), null);
assert.equal(feed('6', 2), 'rank_lost');
assert.equal(feed('6', 2), null);
assert.equal(feed('7', 1), null);
assert.equal(feed('8', 1), 'recovered');
