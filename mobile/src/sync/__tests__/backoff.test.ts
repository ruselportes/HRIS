/**
 * @format
 */

import {BACKOFF, backoffDelay} from '../backoff';

// Jitter pinned to zero: random() = 0.5 maps to a jitter factor of 0.
const noJitter = () => 0.5;

describe('backoffDelay', () => {
  test('starts at the base delay', () => {
    expect(backoffDelay(0, noJitter)).toBe(BACKOFF.baseMs);
  });

  test('doubles each attempt', () => {
    expect(backoffDelay(1, noJitter)).toBe(BACKOFF.baseMs * 2);
    expect(backoffDelay(2, noJitter)).toBe(BACKOFF.baseMs * 4);
    expect(backoffDelay(3, noJitter)).toBe(BACKOFF.baseMs * 8);
  });

  test('never exceeds the cap', () => {
    expect(backoffDelay(30, noJitter)).toBe(BACKOFF.maxMs);
  });

  test('a huge attempt count does not overflow to Infinity', () => {
    const delay = backoffDelay(10_000, noJitter);

    expect(Number.isFinite(delay)).toBe(true);
    expect(delay).toBe(BACKOFF.maxMs);
  });

  test('jitter stays within the configured band', () => {
    // Without jitter, every phone on a site that regains signal together
    // would retry in lockstep and hit the server as one wave.
    const base = BACKOFF.baseMs * 4;
    const lowest = backoffDelay(2, () => 0);
    const highest = backoffDelay(2, () => 0.999999);

    expect(lowest).toBeGreaterThanOrEqual(Math.round(base * (1 - BACKOFF.jitterRatio)));
    expect(highest).toBeLessThanOrEqual(Math.round(base * (1 + BACKOFF.jitterRatio)));
    expect(lowest).toBeLessThan(highest);
  });

  test('negative or fractional attempts are treated as sane values', () => {
    expect(backoffDelay(-5, noJitter)).toBe(BACKOFF.baseMs);
    expect(backoffDelay(1.9, noJitter)).toBe(BACKOFF.baseMs * 2);
  });

  test('never returns a negative delay', () => {
    expect(backoffDelay(0, () => 0)).toBeGreaterThanOrEqual(0);
  });
});
