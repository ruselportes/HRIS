/**
 * Pure roll-call state machine — no SQLite, no native modules, so this is
 * unit-testable in plain Jest without a device/emulator. The repository
 * (attendanceRepository.ts) is a thin SQLite wrapper around these functions.
 *
 * Matches docs/prototypes/HRIS Foreman Attendance Mobile.dc.html's actual
 * interaction: each roster row is a direct 3-way Present/Late/Absent choice
 * (not a sequential time-in/time-out tap cycle), with Undo reverting to
 * Pending. "The tap writes a timestamp" (prototype's own design note) — for
 * Present/Late only; Absent means the worker never showed, so no time_in.
 *
 * @format
 */

import type {OverrideType, TimeOutType} from '../crypto/payload';

export type AttendanceStatus = 'pending' | 'present' | 'late' | 'absent';

export interface AttendanceRecord {
  employeeId: number;
  date: string; // YYYY-MM-DD, local device date
  status: AttendanceStatus;
  /** Credited arrival, epoch ms. The tap itself unless overrideType says otherwise. */
  timeIn: number | null;
  /** Why timeIn differs from the tap — null for an ordinary tap. */
  overrideType: OverrideType | null;
}

/**
 * How a worker's day ended, once it has. A time-out is its own signed event,
 * later than the roll call it closes; a new roll call (a re-mark or an Undo)
 * clears it, as the server does.
 */
export interface TimeOut {
  /** Epoch ms. The tap itself unless timeOutType says otherwise. */
  timeOut: number | null;
  /** shift_end for Close shift, manual_time for a time the foreman stated. */
  timeOutType: TimeOutType | null;
  /** When the time-out was tapped — beside a stated time, what HR compares. */
  timeOutCapturedAt: number | null;
}

export const NO_TIME_OUT: TimeOut = {timeOut: null, timeOutType: null, timeOutCapturedAt: null};

/**
 * Marked as arrived and not yet out: the workers a time-out can close. Absent
 * and pending workers have no arrival, so the server refuses a time-out for
 * them.
 */
export function isOnSite(record: AttendanceRecord & TimeOut): boolean {
  return (
    (record.status === 'present' || record.status === 'late') &&
    record.timeIn !== null &&
    record.timeOut === null
  );
}

/**
 * An ordinary tap. Clears any earlier override: re-marking a worker by hand
 * records what the foreman just saw, not the credit applied before it.
 */
export function selectStatus(
  record: AttendanceRecord,
  status: 'present' | 'late' | 'absent',
  now: number,
): AttendanceRecord {
  return {
    ...record,
    status,
    timeIn: status === 'absent' ? null : now,
    overrideType: null,
  };
}

export function undoStatus(record: AttendanceRecord): AttendanceRecord {
  return {...record, status: 'pending', timeIn: null, overrideType: null};
}

export function blankRecordFor(employeeId: number, date: string): AttendanceRecord {
  return {employeeId, date, status: 'pending', timeIn: null, overrideType: null};
}

export function todayLocalDate(now: Date = new Date()): string {
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}
