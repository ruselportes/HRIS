/**
 * @format
 */

/*
 * Imported by the mock's real path, not via the spec path. jest.config's
 * moduleNameMapper redirects the spec to this same file, so both resolve to
 * one module instance and the state setters affect what the wrapper reads —
 * while tsc still type-checks against a file that actually exports them.
 */
import {
  __reset,
  __setBootId,
  __setElapsedRealtime,
  __setSystemBacked,
} from '../../native/__mocks__/NativeHrisMonotonicClock';
import {captureClock, clockFieldsForPayload} from '../monotonicClock';
import {canonicalize} from '../payload';

describe('captureClock', () => {
  beforeEach(() => {
    __reset();
  });

  test('returns both clocks plus the boot session', () => {
    __setElapsedRealtime(123_456);
    __setBootId('bc42');

    const reading = captureClock();

    expect(reading.monotonicMs).toBe(123_456);
    expect(reading.bootId).toBe('bc42');
    expect(reading.bootIdSystemBacked).toBe(true);
    expect(reading.wallClockMs).toBeGreaterThan(0);
  });

  test('reports when the boot id came from the weaker fallback', () => {
    // The server uses this to decide how much to trust a boot boundary,
    // rather than assuming every boot id is system-backed.
    __setSystemBacked(false);
    __setBootId('fb0a1b2c3d4e');

    const reading = captureClock();

    expect(reading.bootIdSystemBacked).toBe(false);
    expect(reading.bootId).toBe('fb0a1b2c3d4e');
  });

  test('the monotonic clock is independent of the wall clock', () => {
    // The property TC-01 depends on: moving the wall clock must not move the
    // monotonic reading. Simulated by holding elapsedRealtime fixed while
    // Date.now is rolled back two hours.
    __setElapsedRealtime(500_000);

    const before = captureClock();

    const realNow = Date.now;
    const rolledBack = realNow() - 7_200_000;
    jest.spyOn(Date, 'now').mockReturnValue(rolledBack);

    const after = captureClock();

    expect(after.wallClockMs).toBeLessThan(before.wallClockMs);
    expect(after.monotonicMs).toBe(before.monotonicMs);

    jest.spyOn(Date, 'now').mockRestore();
  });
});

describe('clockFieldsForPayload', () => {
  beforeEach(() => {
    __reset();
  });

  test('produces the payload field names', () => {
    __setElapsedRealtime(86_400_000);
    __setBootId('bc7');

    expect(clockFieldsForPayload()).toEqual({
      monotonic_timestamp: 86_400_000,
      boot_id: 'bc7',
    });
  });

  test('rounds a fractional reading so the payload stays canonical', () => {
    // canonicalize() refuses non-integers, because PHP and JS format floats
    // differently and would produce two different canonical forms.
    __setElapsedRealtime(86_400_000.7);

    const fields = clockFieldsForPayload();

    expect(fields.monotonic_timestamp).toBe(86_400_001);
    expect(Number.isInteger(fields.monotonic_timestamp)).toBe(true);
  });

  test('the produced fields are accepted by canonicalize', () => {
    __setElapsedRealtime(86_400_000.7);

    expect(() =>
      canonicalize({
        employee_id: 1,
        crew_id: 1,
        date: '2026-09-12',
        status: 'present',
        time_in: 1789200000000,
        device_id: 'dev-test',
        prev_hash: null,
        ...clockFieldsForPayload(),
      }),
    ).not.toThrow();
  });
});
