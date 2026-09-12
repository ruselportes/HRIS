/**
 * SHARED TEST VECTOR — backend/tests/Unit/Crypto/AttendancePayloadTest.php
 * asserts the identical canonical string. These two files are the contract
 * between the device signer and the server verifier; if one changes without
 * the other, every signature fails.
 *
 * @format
 */

import {
  AttendancePayloadRecord,
  PAYLOAD_FIELDS,
  canonicalize,
  digest,
} from '../payload';

/** Must equal AttendancePayloadTest::VECTOR_RECORD exactly. */
const VECTOR_RECORD: AttendancePayloadRecord = {
  employee_id: 42,
  crew_id: 7,
  date: '2026-09-12',
  status: 'present',
  time_in: 1789200000000,
  monotonic_timestamp: 86400000,
  boot_id: 'b7f3c1a2',
  device_id: 'dev-mgk3f1-a83bd0e1',
  prev_hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
};

/** Must equal AttendancePayloadTest::VECTOR_CANONICAL exactly. */
const VECTOR_CANONICAL =
  'version=v1\n' +
  'employee_id=42\n' +
  'crew_id=7\n' +
  'date=2026-09-12\n' +
  'status=present\n' +
  'time_in=1789200000000\n' +
  'monotonic_timestamp=86400000\n' +
  'boot_id=b7f3c1a2\n' +
  'device_id=dev-mgk3f1-a83bd0e1\n' +
  'prev_hash=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

/**
 * Independently computed with PHP:
 *   hash('sha256', AttendancePayload::canonicalize(VECTOR_RECORD))
 * Hardcoded rather than derived so a matching bug on both sides cannot hide.
 */
const VECTOR_DIGEST =
  '906e597d139deca5875970dcb94fb0bcce0c472fe17fdc867c7a01b1107ec68d';

describe('canonicalize', () => {
  test('matches the shared vector byte for byte', () => {
    expect(canonicalize(VECTOR_RECORD)).toBe(VECTOR_CANONICAL);
  });

  test('field order is fixed regardless of object key order', () => {
    const reordered = {} as AttendancePayloadRecord;
    [...PAYLOAD_FIELDS].reverse().forEach(field => {
      // @ts-expect-error index assignment across the union
      reordered[field] = VECTOR_RECORD[field];
    });

    expect(canonicalize(reordered)).toBe(VECTOR_CANONICAL);
  });

  test('null time_in is distinct from a time_in of 0', () => {
    const absent = {...VECTOR_RECORD, time_in: null, status: 'absent'};
    const atEpoch = {...VECTOR_RECORD, time_in: 0, status: 'absent'};

    expect(canonicalize(absent)).not.toBe(canonicalize(atEpoch));
    expect(canonicalize(absent)).toContain('time_in=\n');
    expect(canonicalize(atEpoch)).toContain('time_in=0\n');
  });

  test('null prev_hash renders empty, for the first record in a chain', () => {
    const first = {...VECTOR_RECORD, prev_hash: null};

    expect(canonicalize(first)).toContain('prev_hash=');
    expect(canonicalize(first).endsWith('prev_hash=')).toBe(true);
  });

  test('a line break in any field is rejected', () => {
    const injected = {
      ...VECTOR_RECORD,
      device_id: 'x\nprev_hash=0000000000000000000000000000000000000000000000000000000000000000',
    };

    expect(() => canonicalize(injected)).toThrow(/line break/);
  });

  test('non-integer numbers are rejected rather than formatted', () => {
    // PHP and JS disagree on float rendering at the edges, so a float would
    // silently produce two different canonical forms.
    const floaty = {...VECTOR_RECORD, monotonic_timestamp: 86400000.5};

    expect(() => canonicalize(floaty)).toThrow(/must be an integer/);
  });

  test('numbers beyond safe integer range are rejected', () => {
    const huge = {...VECTOR_RECORD, time_in: 1e20};

    expect(() => canonicalize(huge)).toThrow(/safe integer/);
  });

  test('a missing field is rejected rather than silently defaulted', () => {
    const incomplete = {...VECTOR_RECORD};
    // @ts-expect-error deliberately removing a required field
    delete incomplete.monotonic_timestamp;

    expect(() => canonicalize(incomplete)).toThrow(/missing required field/);
  });
});

describe('digest', () => {
  test('matches the digest computed independently by PHP', () => {
    expect(digest(VECTOR_RECORD)).toBe(VECTOR_DIGEST);
  });

  test('every field is covered — mutating any one changes the digest', () => {
    const baseline = digest(VECTOR_RECORD);

    PAYLOAD_FIELDS.forEach(field => {
      const mutated = {...VECTOR_RECORD};
      const current = mutated[field];
      // @ts-expect-error index assignment across the union
      mutated[field] = typeof current === 'number' ? current + 1 : `${current}x`;

      expect(digest(mutated)).not.toBe(baseline);
    });
  });
});
