/**
 * @format
 */

import {NO_TIME_OUT, blankRecordFor, isOnSite} from '../attendanceLogic';
import {
  DEFAULT_SHIFT,
  canCloseShift,
  creditAtShiftStart,
  earliestTimeOut,
  formatGap,
  formatSiteTime,
  formatSiteWeekday,
  isLateStart,
  minutesSinceShiftStart,
  shiftEndMs,
  shiftStartMs,
  siteDateOf,
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

describe('site calendar', () => {
  // 23:59 Manila on Sun 13 Sep is 15:59 UTC — still Sunday, whatever the phone's zone.
  const endOfSunday = siteTimeMs('2026-09-13', 23, 59, DEFAULT_SHIFT);

  test('weekday and date are read in site time', () => {
    expect(formatSiteWeekday(endOfSunday, DEFAULT_SHIFT)).toBe('Sun');
    expect(siteDateOf(endOfSunday, DEFAULT_SHIFT)).toBe('2026-09-13');
  });

  test('00:30 Manila is already the next site day, though still the day before in UTC', () => {
    expect(siteDateOf(siteTimeMs('2026-09-14', 0, 30, DEFAULT_SHIFT), DEFAULT_SHIFT)).toBe('2026-09-14');
  });
});

describe('time-out', () => {
  /*
   * Pinned to the server: TimeOutPolicyTest asserts 16:00 +08:00 is 08:00 UTC
   * on this date. A Close-shift time-out must equal it exactly.
   */
  test('shift end is 16:00 Manila time, whatever timezone the phone is in', () => {
    expect(shiftEndMs(DATE, DEFAULT_SHIFT)).toBe(Date.UTC(2026, 8, 12, 8, 0));
  });

  test('Close shift opens exactly at shift end and stays open for the rest of the day', () => {
    const end = shiftEndMs(DATE, DEFAULT_SHIFT);

    expect(canCloseShift(end - 1, DATE, DEFAULT_SHIFT)).toBe(false);
    expect(canCloseShift(end, DATE, DEFAULT_SHIFT)).toBe(true);
    expect(canCloseShift(siteTimeMs(DATE, 21, 30, DEFAULT_SHIFT), DATE, DEFAULT_SHIFT)).toBe(true);
  });

  test('a stated time-out starts the minute after the time in', () => {
    const timeIn = siteTimeMs(DATE, 6, 58, DEFAULT_SHIFT) + 23_000;

    expect(earliestTimeOut(timeIn)).toBe(siteTimeMs(DATE, 6, 59, DEFAULT_SHIFT));
    // On the minute exactly: the next minute, since equal is not after.
    expect(earliestTimeOut(siteTimeMs(DATE, 7, 0, DEFAULT_SHIFT))).toBe(
      siteTimeMs(DATE, 7, 1, DEFAULT_SHIFT),
    );
  });

  test('stepping a time-out cannot go back to the time in', () => {
    const earliest = siteTimeMs(DATE, 7, 1, DEFAULT_SHIFT);
    const now = siteTimeMs(DATE, 17, 0, DEFAULT_SHIFT);

    expect(stepManualTime(earliest, -60, DATE, now, DEFAULT_SHIFT, earliest)).toBe(earliest);
    expect(stepManualTime(earliest, 5, DATE, now, DEFAULT_SHIFT, earliest)).toBe(earliest + 5 * MINUTE);
  });

  test('only a worker marked and not yet out is on site', () => {
    const blank = {...blankRecordFor(1, DATE), ...NO_TIME_OUT};
    const present = {...blank, status: 'present' as const, timeIn: siteTimeMs(DATE, 7, 0, DEFAULT_SHIFT)};

    expect(isOnSite(blank)).toBe(false);
    expect(isOnSite({...blank, status: 'absent'})).toBe(false);
    expect(isOnSite(present)).toBe(true);
    expect(isOnSite({...present, status: 'late'})).toBe(true);
    expect(isOnSite({...present, timeOut: shiftEndMs(DATE, DEFAULT_SHIFT)})).toBe(false);
  });
});
