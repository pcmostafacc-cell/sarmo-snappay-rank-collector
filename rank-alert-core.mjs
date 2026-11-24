export function updateRankTarget(previous = {}, observation, options = {}) {
  const state = {
    status: previous.status || 'unknown',
    missStreak: previous.missStreak || 0,
    meetStreak: previous.meetStreak || 0,
    lastObservedAt: previous.lastObservedAt || null,
    lastRank: previous.lastRank ?? null,
  };
  const target = Number(options.targetRank);
  const field = options.rankField === 'adRank' ? 'adRank' : 'gridRank';
  if (!Number.isInteger(target) || target < 1) throw new Error('Invalid target rank');
  if (!observation?.sourceValid || observation.observedAt === state.lastObservedAt) {
    // A failed fetch interrupts consecutive evidence without declaring a loss.
    if (!observation?.sourceValid) {
      state.missStreak = 0;
      state.meetStreak = 0;
    }
    return { state, event: null };
  }
  const rank = observation[field];
  if (rank == null && !observation.scanComplete) return { state, event: null };
  const met = Number.isInteger(rank) && rank > 0 && rank <= target;
  state.lastObservedAt = observation.observedAt;
  state.lastRank = rank ?? null;
  state.meetStreak = met ? state.meetStreak + 1 : 0;
  state.missStreak = met ? 0 : state.missStreak + 1;
  let event = null;
  if (met && state.meetStreak >= 2 && state.status !== 'in_target') {
    event = state.status === 'out_of_target' ? 'recovered' : 'target_met';
    state.status = 'in_target';
  } else if (!met && state.missStreak >= 2 && state.status !== 'out_of_target') {
    event = state.status === 'in_target' ? 'rank_lost' : 'target_unmet';
    state.status = 'out_of_target';
  }
  return { state, event };
}
