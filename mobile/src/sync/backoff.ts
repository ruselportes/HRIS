/**
 * Retry delay with exponential backoff and jitter (Phase 6).
 *
 * Pure — no timers, no state — so it is testable without faking time.
 *
 * @format
 */

export const BACKOFF = {
  /** First retry waits about this long. */
  baseMs: 2_000,
  /** Never wait longer than this between attempts. */
  maxMs: 5 * 60_000,
  /** Fraction of the delay randomised, to stop devices retrying in lockstep. */
  jitterRatio: 0.3,
};

/**
 * Delay before retry attempt `attempt` (0-based).
 *
 * Doubles each attempt up to the cap. Jitter matters more than it looks: when a
 * site regains signal, every foreman's phone reconnects at once, and without
 * jitter they would all retry on the same schedule and hit the server as a
 * synchronised wave on every attempt.
 *
 * `random` is injectable so tests can pin the jitter.
 */
export function backoffDelay(
  attempt: number,
  random: () => number = Math.random,
): number {
  const safeAttempt = Math.max(0, Math.floor(attempt));

  // Cap the exponent before exponentiating — 2 ** 60 is not a delay anyone
  // wants, and a large enough attempt count would overflow to Infinity.
  const exponential = BACKOFF.baseMs * 2 ** Math.min(safeAttempt, 20);
  const capped = Math.min(exponential, BACKOFF.maxMs);

  // Symmetric jitter in [-ratio, +ratio] around the capped value.
  const jitter = capped * BACKOFF.jitterRatio * (random() * 2 - 1);

  return Math.max(0, Math.round(capped + jitter));
}
