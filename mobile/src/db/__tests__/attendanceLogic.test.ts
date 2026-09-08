/**
 * @format
 */

import {
  blankRecordFor,
  selectStatus,
  todayLocalDate,
  undoStatus,
} from '../attendanceLogic';

describe('selectStatus', () => {
  test('present stamps timeIn', () => {
    const record = blankRecordFor(1, '2026-09-08');
    const result = selectStatus(record, 'present', 1000);

    expect(result.status).toBe('present');
    expect(result.timeIn).toBe(1000);
  });

  test('late stamps timeIn', () => {
    const record = blankRecordFor(1, '2026-09-08');
    const result = selectStatus(record, 'late', 2000);

    expect(result.status).toBe('late');
    expect(result.timeIn).toBe(2000);
  });

  test('absent leaves timeIn null (worker never showed)', () => {
    const record = blankRecordFor(1, '2026-09-08');
    const result = selectStatus(record, 'absent', 3000);

    expect(result.status).toBe('absent');
    expect(result.timeIn).toBeNull();
  });

  test('re-selecting overwrites the previous choice and timestamp', () => {
    const record = blankRecordFor(1, '2026-09-08');
    const asLate = selectStatus(record, 'late', 1000);
    const asPresent = selectStatus(asLate, 'present', 5000);

    expect(asPresent.status).toBe('present');
    expect(asPresent.timeIn).toBe(5000);
  });
});

describe('undoStatus', () => {
  test('reverts a marked record back to pending with no timestamp', () => {
    const record = blankRecordFor(1, '2026-09-08');
    const marked = selectStatus(record, 'present', 1000);
    const undone = undoStatus(marked);

    expect(undone.status).toBe('pending');
    expect(undone.timeIn).toBeNull();
    expect(undone.overrideFlag).toBe(false);
  });
});

describe('blankRecordFor', () => {
  test('starts pending with null timestamp', () => {
    const record = blankRecordFor(42, '2026-09-08');

    expect(record).toEqual({
      employeeId: 42,
      date: '2026-09-08',
      status: 'pending',
      timeIn: null,
      overrideFlag: false,
    });
  });
});

describe('todayLocalDate', () => {
  test('formats as YYYY-MM-DD', () => {
    const fixed = new Date(2026, 8, 8); // Sept 8, 2026 (local, month is 0-indexed)
    expect(todayLocalDate(fixed)).toBe('2026-09-08');
  });

  test('zero-pads single-digit month/day', () => {
    const fixed = new Date(2026, 0, 5); // Jan 5, 2026
    expect(todayLocalDate(fixed)).toBe('2026-01-05');
  });
});
