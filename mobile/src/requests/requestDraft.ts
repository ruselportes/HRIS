/**
 * Request form logic (Phase 9 — UC-10, PR-10): what a foreman is filing,
 * checked the way the server will check it, and turned into API bodies.
 *
 * Pure — no network, no SQLite — so it is tested without a device. The server
 * re-checks everything and has the last word; these checks only spare the
 * foreman a round trip that is bound to be refused.
 *
 * Overtime is one request per worker (POST /overtimes). Several workers filed
 * together share a batch key, so the endorser and HR can decide them as one.
 * The phone sends the window only: the server derives the paid hours from it,
 * exactly as payroll will pay them.
 *
 * @format
 */

import type {ShiftConfig} from '../db/shiftRules';

export type LeaveType = 'sick' | 'vacation' | 'personal' | 'bereavement' | 'leave_without_pay';

/** As the server accepts them (LeaveRequest::TYPES). */
export const LEAVE_TYPES: {value: LeaveType; label: string}[] = [
  {value: 'sick', label: 'Sick'},
  {value: 'vacation', label: 'Vacation'},
  {value: 'personal', label: 'Personal'},
  {value: 'bereavement', label: 'Bereavement'},
  {value: 'leave_without_pay', label: 'Without pay'},
];

/** The prototype's tap-one reasons; "Other" opens a text box. */
export const OVERTIME_REASONS = [
  'Concrete pour must finish',
  'Catching up on schedule',
  'Weather delay earlier',
];

export type OvertimeDraft = {
  kind: 'overtime';
  workerIds: number[];
  date: string; // YYYY-MM-DD, site date
  start: string; // HH:MM
  hours: number; // window length; the paid hours are the server's to compute
  reason: string;
  /** Set on the first send, so a retry of the ones that failed joins the same batch. */
  batchKey: string | null;
};

export type LeaveDraft = {
  kind: 'leave';
  workerId: number | null;
  leaveType: LeaveType;
  dateFrom: string; // YYYY-MM-DD
  days: number;
  reason: string;
};

export type RequestDraft = OvertimeDraft | LeaveDraft;

export type ApiRequest = {endpoint: '/overtimes' | '/leaves'; employeeId: number; body: Record<string, unknown>};

const DAY = 1440;

/** Art. 86 night hours, 22:00–06:00: fixed by law, not company policy. */
const NIGHT_START = 22 * 60;
const NIGHT_END = 6 * 60;

export function addDays(ymd: string, days: number): string {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

export function minutesOf(clock: string): number {
  const [h, m] = clock.split(':').map(Number);
  return h * 60 + m;
}

/** HH:MM for minutes from midnight, wrapping past 24:00. */
export function clockOf(minutes: number): string {
  const wrapped = ((minutes % DAY) + DAY) % DAY;
  return `${String(Math.floor(wrapped / 60)).padStart(2, '0')}:${String(wrapped % 60).padStart(2, '0')}`;
}

/** End of a window that starts at `start` and lasts `hours`. */
export function endTime(start: string, hours: number): string {
  return clockOf(minutesOf(start) + Math.round(hours * 60));
}

export function crossesMidnight(start: string, hours: number): boolean {
  return minutesOf(start) + Math.round(hours * 60) > DAY;
}

function overlap(aStart: number, aEnd: number, bStart: number, bEnd: number): number {
  return Math.max(0, Math.min(aEnd, bEnd) - Math.max(aStart, bStart));
}

/**
 * What a window is likely to pay, the way the server's TimeWorked::overtime
 * counts it: the part inside the regular shift (today's or, past midnight,
 * tomorrow's) is not overtime, and night hours are those between 22:00 and
 * 06:00. An estimate for the foreman; the server's figure is the one filed.
 */
export function overtimeEstimate(
  start: string,
  hours: number,
  shift: Pick<ShiftConfig, 'start' | 'end'>,
): {paidHours: number; inShiftHours: number; nightHours: number} {
  const from = minutesOf(start);
  const to = from + Math.round(hours * 60);
  const shiftStart = minutesOf(shift.start);
  const shiftEnd = minutesOf(shift.end);

  const inShift =
    overlap(from, to, shiftStart, shiftEnd) + overlap(from, to, shiftStart + DAY, shiftEnd + DAY);
  const night = overlap(from, to, 0, NIGHT_END) + overlap(from, to, NIGHT_START, NIGHT_END + DAY);

  return {
    paidHours: Math.max(0, to - from - inShift) / 60,
    inShiftHours: inShift / 60,
    nightHours: night / 60,
  };
}

/** What would stop the server accepting this draft, in the foreman's words. Empty = fine. */
export function problemsWith(draft: RequestDraft, today: string, shift: Pick<ShiftConfig, 'start' | 'end'>): string[] {
  const problems: string[] = [];

  if (draft.kind === 'overtime') {
    if (draft.workerIds.length === 0) {
      problems.push('Choose at least one worker.');
    }
    if (draft.date < today) {
      problems.push('Overtime cannot be filed for a day that has already passed.');
    }
    if (draft.hours <= 0) {
      problems.push('Set how many hours.');
    } else if (overtimeEstimate(draft.start, draft.hours, shift).paidHours <= 0) {
      problems.push(`That window is inside the ${shift.start}–${shift.end} shift, so none of it is overtime.`);
    }
    return problems;
  }

  if (draft.workerId === null) {
    problems.push('Choose who the leave is for.');
  }
  if (draft.days < 1) {
    problems.push('A leave is at least one day.');
  }
  if (draft.dateFrom < today && draft.leaveType !== 'sick') {
    problems.push('Days that have already passed can only be filed as sick leave.');
  }
  if (!draft.reason.trim()) {
    problems.push('Give a reason for the leave.');
  }
  return problems;
}

/** The API calls a draft becomes: one per worker for overtime, one for a leave. */
export function toApiRequests(draft: RequestDraft, batchKey: string | null): ApiRequest[] {
  if (draft.kind === 'overtime') {
    const shared = draft.workerIds.length > 1 ? batchKey : null;

    return draft.workerIds.map(employeeId => ({
      endpoint: '/overtimes',
      employeeId,
      body: {
        employee_id: employeeId,
        ot_date: draft.date,
        start_time: draft.start,
        end_time: endTime(draft.start, draft.hours),
        ...(draft.reason.trim() ? {reason: draft.reason.trim()} : {}),
        ...(shared ? {batch_key: shared} : {}),
      },
    }));
  }

  if (draft.workerId === null) {
    return [];
  }

  return [
    {
      endpoint: '/leaves',
      employeeId: draft.workerId,
      body: {
        employee_id: draft.workerId,
        leave_type: draft.leaveType,
        date_from: draft.dateFrom,
        date_to: addDays(draft.dateFrom, draft.days - 1),
        reason: draft.reason.trim(),
      },
    },
  ];
}

/**
 * A saved draft whose date has gone is dropped rather than sent late: the
 * server refuses past overtime, and past leave other than sick.
 */
export function isExpired(draft: RequestDraft, today: string): boolean {
  if (draft.kind === 'overtime') {
    return draft.date < today;
  }
  return draft.dateFrom < today && draft.leaveType !== 'sick';
}

/** Not a secret, only a grouping label, so Math.random is enough (Hermes has no crypto.randomUUID). */
export function newBatchKey(now: number = Date.now()): string {
  return `ot-${now.toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
