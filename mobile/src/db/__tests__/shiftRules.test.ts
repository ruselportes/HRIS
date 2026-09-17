/**
 * @format
 */

import {blankRecordFor} from '../attendanceLogic';
import {
  DEFAULT_SHIFT,
  creditAtShiftStart,
  formatGap,
  formatSiteTime,
  isLateStart,
  minutesSinceShiftStart,
  shiftStartMs,
  siteTimeMs,
  stepManualTime,
  withManualTime,
} from '../shiftRules';

const DATE = '2026-09-12';
const MINUTE = 60_000;

describe('shiftStartMs', () => {
  /*
   * Pinned to the server: TimeInPolicyTest asserts the same instant for its
   * date. 07:00 +08:00 is 23:00 UTC the previous day. If this drifts, every
   * shift credit the phone offers is refused on sync.
   */
  test('is 07:00 Manila time, whatever timezone the phone is in', () => {
    expect(shiftStartMs(DATE, DEFAULT_SHIFT)).toBe(Date.UTC(2026, 8, 11, 23, 0));
  });
});

describe('isLateStart', () => {
  const start = shiftStartMs(DATE, DEFAULT_SHIFT);

  test('not late inside the 15 minute grace window', () => {
    expect(isLateStart(start + 14 * MINUTE, DATE, DEFAULT_SHIFT)).toBe(false);
  });

  test('late exactly at the end of grace — the same boundary the server allows', () => {
    expect(isLateStart(start + 15 * MINUTE, DATE, DEFAULT_SHIFT)).toBe(true);
  });

  test('late at 09:20, the TC-04 scenario', () => {
    expect(isLateStart(start + 140 * MINUTE, DATE, DEFAULT_SHIFT)).toBe(true);
  });
});

describe('gap', () => {
  test('counts whole minutes since shift start', () => {
    const start = shiftStartMs(DATE, DEFAULT_SHIFT);
    expect(minutesSinceShiftStart(start + 154 * MINUTE + 30_000, DATE, DEFAULT_SHIFT)).toBe(154);
  });

  test('formats hours and minutes', () => {
    expect(formatGap(154)).toBe('2 h 34 m');
    expect(formatGap(45)).toBe('45 m');
  });
});

describe('creditAtShiftStart', () => {
  test('marks Present at exactly shift start, as a signed shift_credit', () => {
    const credited = creditAtShiftStart(blankRecordFor(7, DATE), DATE, DEFAULT_SHIFT);

    expect(credited.status).toBe('present');
    expect(credited.timeIn).toBe(shiftStartMs(DATE, DEFAULT_SHIFT));
    expect(credited.overrideType).toBe('shift_credit');
  });
});

describe('manual time', () => {
  test('records the stated arrival as a manual_time override', () => {
    const at = siteTimeMs(DATE, 7, 50, DEFAULT_SHIFT);
    const record = withManualTime(blankRecordFor(7, DATE), 'late', at);

    expect(record.status).toBe('late');
    expect(record.timeIn).toBe(at);
    expect(record.overrideType).toBe('manual_time');
  });

  test('stepping cannot pass the current minute', () => {
    const now = siteTimeMs(DATE, 9, 20, DEFAULT_SHIFT) + 30_000;
    const at = siteTimeMs(DATE, 9, 10, DEFAULT_SHIFT);

    expect(stepManualTime(at, 5, DATE, now, DEFAULT_SHIFT)).toBe(siteTimeMs(DATE, 9, 15, DEFAULT_SHIFT));
    expect(stepManualTime(at, 60, DATE, now, DEFAULT_SHIFT)).toBe(siteTimeMs(DATE, 9, 20, DEFAULT_SHIFT));
  });

  test('stepping cannot leave the day', () => {
    const now = siteTimeMs(DATE, 9, 20, DEFAULT_SHIFT);
    const at = siteTimeMs(DATE, 0, 30, DEFAULT_SHIFT);

    expect(stepManualTime(at, -60, DATE, now, DEFAULT_SHIFT)).toBe(siteTimeMs(DATE, 0, 0, DEFAULT_SHIFT));
  });

  test('site time round-trips through formatting', () => {
    expect(formatSiteTime(siteTimeMs(DATE, 7, 5, DEFAULT_SHIFT), DEFAULT_SHIFT)).toBe('07:05');
    expect(formatSiteTime(shiftStartMs(DATE, DEFAULT_SHIFT), DEFAULT_SHIFT)).toBe('07:00');
  });
});
