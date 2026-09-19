/**
 * @format
 */

import {
  LeaveDraft,
  OvertimeDraft,
  addDays,
  crossesMidnight,
  endTime,
  isExpired,
  newBatchKey,
  overtimeEstimate,
  problemsWith,
  toApiRequests,
} from '../requestDraft';

const SHIFT = {start: '07:00', end: '16:00'};
const TODAY = '2026-09-21';

const overtime = (overrides: Partial<OvertimeDraft> = {}): OvertimeDraft => ({
  kind: 'overtime',
  workerIds: [11, 12],
  date: TODAY,
  start: '16:00',
  hours: 3,
  reason: 'Concrete pour must finish',
  batchKey: null,
  ...overrides,
});

const leave = (overrides: Partial<LeaveDraft> = {}): LeaveDraft => ({
  kind: 'leave',
  workerId: 11,
  leaveType: 'vacation',
  dateFrom: '2026-09-24',
  days: 2,
  reason: 'Family matter',
  ...overrides,
});

describe('dates and windows', () => {
  test('days add across a month end', () => {
    expect(addDays('2026-09-30', 1)).toBe('2026-10-01');
    expect(addDays('2026-10-01', -1)).toBe('2026-09-30');
  });

  test('a window past midnight wraps its end time', () => {
    expect(endTime('16:00', 3)).toBe('19:00');
    expect(endTime('22:00', 4)).toBe('02:00');
    expect(endTime('17:30', 2.5)).toBe('20:00');
    expect(crossesMidnight('22:00', 4)).toBe(true);
    expect(crossesMidnight('16:00', 3)).toBe(false);
  });
});

/*
 * Mirrors backend TimeWorked::overtime, which payroll pays by: the part inside
 * the regular shift (today's, or tomorrow's past midnight) is not overtime,
 * and night hours are 22:00-06:00.
 */
describe('overtime estimate', () => {
  test.each([
    ['after the shift', '16:00', 3, {paidHours: 3, inShiftHours: 0, nightHours: 0}],
    ['starting inside the shift', '15:00', 3, {paidHours: 2, inShiftHours: 1, nightHours: 0}],
    ['into the night', '20:00', 4, {paidHours: 4, inShiftHours: 0, nightHours: 2}],
    ['early morning into the shift', '05:00', 3, {paidHours: 2, inShiftHours: 1, nightHours: 1}],
    ['overnight into the next shift', '22:00', 10, {paidHours: 9, inShiftHours: 1, nightHours: 8}],
  ])('%s', (_label, start, hours, expected) => {
    expect(overtimeEstimate(start as string, hours as number, SHIFT)).toEqual(expected);
  });
});

describe('what the server would refuse', () => {
  test('a good overtime draft has no problems', () => {
    expect(problemsWith(overtime(), TODAY, SHIFT)).toEqual([]);
  });

  test('overtime needs workers, a day not yet past, and time outside the shift', () => {
    expect(problemsWith(overtime({workerIds: []}), TODAY, SHIFT)).toContain('Choose at least one worker.');
    expect(problemsWith(overtime({date: '2026-09-20'}), TODAY, SHIFT)).toContain(
      'Overtime cannot be filed for a day that has already passed.',
    );
    expect(problemsWith(overtime({start: '09:00', hours: 3}), TODAY, SHIFT)).toContain(
      'That window is inside the 07:00–16:00 shift, so none of it is overtime.',
    );
  });

  test('leave needs a person and a reason, and past days only as sick leave', () => {
    expect(problemsWith(leave(), TODAY, SHIFT)).toEqual([]);
    expect(problemsWith(leave({workerId: null}), TODAY, SHIFT)).toContain('Choose who the leave is for.');
    expect(problemsWith(leave({reason: '  '}), TODAY, SHIFT)).toContain('Give a reason for the leave.');
    expect(problemsWith(leave({dateFrom: '2026-09-18'}), TODAY, SHIFT)).toContain(
      'Days that have already passed can only be filed as sick leave.',
    );
    expect(problemsWith(leave({dateFrom: '2026-09-18', leaveType: 'sick'}), TODAY, SHIFT)).toEqual([]);
  });
});

describe('API requests', () => {
  test('overtime is one request per worker, sharing a batch key, with the window and no hours', () => {
    const requests = toApiRequests(overtime(), 'ot-batch');

    expect(requests.map(r => r.employeeId)).toEqual([11, 12]);
    for (const r of requests) {
      expect(r.endpoint).toBe('/overtimes');
      expect(r.body).toEqual({
        employee_id: r.employeeId,
        ot_date: TODAY,
        start_time: '16:00',
        end_time: '19:00',
        reason: 'Concrete pour must finish',
        batch_key: 'ot-batch',
      });
    }
  });

  test('a single worker needs no batch, and a blank reason is left out', () => {
    const [request] = toApiRequests(overtime({workerIds: [11], reason: ' '}), 'ot-batch');

    expect(request.body).not.toHaveProperty('batch_key');
    expect(request.body).not.toHaveProperty('reason');
  });

  test('a leave runs from its first day for the number of days', () => {
    expect(toApiRequests(leave(), null)).toEqual([
      {
        endpoint: '/leaves',
        employeeId: 11,
        body: {employee_id: 11, leave_type: 'vacation', date_from: '2026-09-24', date_to: '2026-09-25', reason: 'Family matter'},
      },
    ]);
  });
});

describe('saved drafts', () => {
  test('a draft whose day has passed expires, except sick leave, which may go back', () => {
    expect(isExpired(overtime({date: '2026-09-20'}), TODAY)).toBe(true);
    expect(isExpired(overtime(), TODAY)).toBe(false);
    expect(isExpired(leave({dateFrom: '2026-09-20'}), TODAY)).toBe(true);
    expect(isExpired(leave({dateFrom: '2026-09-20', leaveType: 'sick'}), TODAY)).toBe(false);
  });

  test('batch keys fit the server limit', () => {
    const key = newBatchKey(1_789_200_000_000);
    expect(key.startsWith('ot-')).toBe(true);
    expect(key.length).toBeLessThanOrEqual(64);
    expect(newBatchKey()).not.toBe(newBatchKey());
  });
});
