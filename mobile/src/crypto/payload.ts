/**
 * Canonical serialization of an attendance record (Phase 5, supports UC-04).
 *
 * THIS IS THE MIRROR OF backend/app/Services/Crypto/AttendancePayload.php.
 * The two MUST produce byte-identical output. If they drift, every signature
 * this device produces is rejected server-side, and the failure looks like a
 * crypto bug rather than a serialization bug. Both sides are pinned to the
 * same shared vector — here in __tests__/payload.test.ts and there in
 * tests/Unit/Crypto/AttendancePayloadTest.php. Change the format only by
 * bumping PAYLOAD_VERSION and updating both sides and both vectors together.
 *
 * Why not JSON: key ordering, unicode escaping and number rendering all
 * differ between JS's JSON.stringify and PHP's json_encode.
 *
 * @format
 */

import {sha256} from '@noble/hashes/sha2.js';
import {bytesToHex, utf8ToBytes} from '@noble/hashes/utils.js';

/** Must match config('crypto.payload_version') on the server. */
export const PAYLOAD_VERSION = 'v1';

/** Fixed order — the order is part of the format. Do not sort. */
export const PAYLOAD_FIELDS = [
  'employee_id',
  'crew_id',
  'date',
  'status',
  'time_in',
  'monotonic_timestamp',
  'boot_id',
  'device_id',
  'prev_hash',
] as const;

export type PayloadField = (typeof PAYLOAD_FIELDS)[number];

export type AttendancePayloadRecord = {
  employee_id: number;
  crew_id: number;
  date: string;
  status: string;
  time_in: number | null;
  monotonic_timestamp: number;
  boot_id: string;
  device_id: string;
  prev_hash: string | null;
};

function normalize(field: string, value: unknown): string {
  if (value === null || value === undefined) {
    return '';
  }

  if (typeof value === 'boolean') {
    return value ? '1' : '0';
  }

  if (typeof value === 'number') {
    // Non-integers are refused rather than rendered. PHP and JS disagree on
    // float formatting at the edges — (string)1e20 is "1.0E+20" in PHP but
    // "100000000000000000000" in JS — so a float here would silently produce
    // two different canonical forms and every signature would fail.
    if (!Number.isInteger(value)) {
      throw new Error(
        `Attendance payload field [${field}] must be an integer, got ${value}.`,
      );
    }
    if (!Number.isSafeInteger(value)) {
      throw new Error(
        `Attendance payload field [${field}] exceeds safe integer range: ${value}.`,
      );
    }
    return String(value);
  }

  const asString = String(value);

  if (asString.includes('\n') || asString.includes('\r')) {
    throw new Error(
      `Attendance payload field [${field}] contains a line break, which would ` +
        'forge a field boundary in the canonical form.',
    );
  }

  return asString;
}

export function canonicalize(record: AttendancePayloadRecord): string {
  const lines = [`version=${PAYLOAD_VERSION}`];

  for (const field of PAYLOAD_FIELDS) {
    if (!(field in record)) {
      throw new Error(`Attendance payload is missing required field [${field}].`);
    }

    lines.push(`${field}=${normalize(field, record[field])}`);
  }

  return lines.join('\n');
}

/** SHA-256 of the canonical form, lowercase hex — matches PHP's hash('sha256', ...). */
export function digest(record: AttendancePayloadRecord): string {
  return bytesToHex(sha256(utf8ToBytes(canonicalize(record))));
}
