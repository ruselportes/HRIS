/**
 * Shift rules on the phone (Phase 7 — UC-05, STD TC-04).
 *
 * The device-side mirror of backend TimeInPolicy and TimeOutPolicy. The
 * override and Close shift are offered offline, so the phone has to reach the
 * same answers the server will check later: when the foreman counts as late,
 * and exactly which instants "07:00" and "16:00" are. If the two disagree, a
 * credit the phone offers is refused on sync.
 *
 * Pure — no SQLite, no clock reads — so it is tested without a device.
 *
 * @format
 */

import type {AttendanceRecord} from './attendanceLogic';

/** As sent by GET /api/me/crew under `shift`. */
export type ShiftConfig = {
  start: string; // "HH:MM", site time
  /** "HH:MM", site time. Close shift is offered from here, and credits exactly this. */
  end: string;
  late_override_grace_minutes: number;
  timezone: string;
  utc_offset_minutes: number;
};

/** The server's defaults, used only until the first roster fetch. */
export const DEFAULT_SHIFT: ShiftConfig = {
  start: '07:00',
  end: '16:00',
  late_override_grace_minutes: 15,
  timezone: 'Asia/Manila',
  utc_offset_minutes: 480,
};

const MINUTE = 60_000;

/**
 * Epoch ms of shift start on a date, in SITE time rather than the phone's own
 * timezone. Computed from the offset, not the device locale, so a phone set to
 * a different timezone still credits the same instant the server expects.
 */
export function shiftStartMs(date: string, shift: ShiftConfig): number {
  const [y, m, d] = date.split('-').map(Number);
  const [hh, mm] = shift.start.split(':').map(Number);

  return Date.UTC(y, m - 1, d, hh, mm) - shift.utc_offset_minutes * MINUTE;
}

/**
 * Epoch ms of shift end on a date, in site time — the device-side mirror of
 * backend TimeOutPolicy::shiftEndMs, which a Close-shift time-out must equal
 * exactly.
 */
export function shiftEndMs(date: string, shift: ShiftConfig): number {
  const [hh, mm] = shift.end.split(':').map(Number);
  return siteTimeMs(date, hh, mm, shift);
}

/**
 * Whether Close shift may be used now. Not before shift end: crediting 16:00
 * at 15:00 would pay for an hour nobody has worked yet, and the server refuses
 * it. Any time after it on the same day is fine — a foreman who closes at
 * 18:30 still credits exactly 16:00.
 */
export function canCloseShift(nowMs: number, date: string, shift: ShiftConfig): boolean {
  return nowMs >= shiftEndMs(date, shift);
}

/**
 * The earliest time-out that can be stated for a worker: the first whole
 * minute after their time in. The server refuses a time-out at or before it.
 */
export function earliestTimeOut(timeInMs: number): number {
  return timeInMs - (timeInMs % MINUTE) + MINUTE;
}

/**
 * Whether opening roll call now counts as a late start. Uses the same boundary
 * as the server, which refuses a credit captured inside the grace window.
 */
export function isLateStart(nowMs: number, date: string, shift: ShiftConfig): boolean {
  return nowMs >= shiftStartMs(date, shift) + shift.late_override_grace_minutes * MINUTE;
}

/** Whole minutes since shift start, for "2 h 34 m ago". Never negative. */
export function minutesSinceShiftStart(nowMs: number, date: string, shift: ShiftConfig): number {
  return Math.max(0, Math.floor((nowMs - shiftStartMs(date, shift)) / MINUTE));
}

/** "2 h 34 m" / "45 m". */
export function formatGap(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return h > 0 ? `${h} h ${m} m` : `${m} m`;
}

/**
 * A worker marked Present under the shift credit. Present only: the server
 * refuses a credit on a Late worker, since "late" and "arrived at 07:00"
 * contradict each other.
 */
export function creditAtShiftStart(
  record: AttendanceRecord,
  date: string,
  shift: ShiftConfig,
): AttendanceRecord {
  return {
    ...record,
    status: 'present',
    timeIn: shiftStartMs(date, shift),
    overrideType: 'shift_credit',
  };
}

/** An arrival time the foreman states for one worker ("Set each time myself"). */
export function withManualTime(
  record: AttendanceRecord,
  status: 'present' | 'late',
  timeInMs: number,
): AttendanceRecord {
  return {...record, status, timeIn: timeInMs, overrideType: 'manual_time'};
}

/** Epoch ms for HH:MM on a date in site time — the manual time picker's value. */
export function siteTimeMs(date: string, hours: number, minutes: number, shift: ShiftConfig): number {
  const [y, m, d] = date.split('-').map(Number);
  return Date.UTC(y, m - 1, d, hours, minutes) - shift.utc_offset_minutes * MINUTE;
}

/**
 * Move a manual time by some minutes, kept inside what the server accepts: on
 * the same day, and no later than the current minute — an arrival or a
 * time-out cannot be stated for a moment that has not happened yet. A
 * time-out also passes `earliestMs`, since it must follow the time in.
 */
export function stepManualTime(
  currentMs: number,
  deltaMinutes: number,
  date: string,
  nowMs: number,
  shift: ShiftConfig,
  earliestMs: number | null = null,
): number {
  const dayStart = siteTimeMs(date, 0, 0, shift);
  const lowest = Math.max(dayStart, earliestMs ?? dayStart);
  const latest = nowMs - (nowMs % MINUTE);

  return Math.min(Math.max(currentMs + deltaMinutes * MINUTE, lowest), latest);
}

/** HH:MM in site time for an epoch ms, independent of the phone's timezone. */
export function formatSiteTime(epochMs: number, shift: ShiftConfig): string {
  const site = new Date(epochMs + shift.utc_offset_minutes * MINUTE);
  const hh = String(site.getUTCHours()).padStart(2, '0');
  const mm = String(site.getUTCMinutes()).padStart(2, '0');
  return `${hh}:${mm}`;
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** Short weekday in site time ("Sun"), for "until Sun 23:59". */
export function formatSiteWeekday(epochMs: number, shift: ShiftConfig): string {
  return WEEKDAYS[new Date(epochMs + shift.utc_offset_minutes * MINUTE).getUTCDay()];
}

/** YYYY-MM-DD in site time, for "is this today on site?". */
export function siteDateOf(epochMs: number, shift: ShiftConfig): string {
  return new Date(epochMs + shift.utc_offset_minutes * MINUTE).toISOString().slice(0, 10);
}
