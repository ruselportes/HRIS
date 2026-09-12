/**
 * TEESigner wrapper tests.
 *
 * These assert the wrapper's contract with the native boundary — above all
 * that the bytes handed to the Keystore are exactly the canonical payload the
 * server will verify. Real ECDSA correctness is covered on the PHP side in
 * SignatureVerifierTest, where a signature can actually be verified; asserting
 * it against a mock here would only be testing the mock.
 *
 * @format
 */

import {
  __failNextSign,
  __reset,
  __setSecurityLevel,
  __signedPayloads,
} from '../../native/__mocks__/NativeHrisTeeSigner';
import {AttendancePayloadRecord, canonicalize} from '../payload';
import {
  SIGNING_KEY_ALIAS,
  createSigningKey,
  deleteSigningKey,
  getSecurityLevel,
  hasSigningKey,
  isHardwareBacked,
  signAttendance,
} from '../teeSigner';

const RECORD: AttendancePayloadRecord = {
  employee_id: 42,
  crew_id: 7,
  date: '2026-09-12',
  status: 'present',
  time_in: 1789200000000,
  monotonic_timestamp: 86400000,
  boot_id: 'bc7',
  device_id: 'dev-mgk3f1-a83bd0e1',
  prev_hash: null,
};

beforeEach(() => {
  __reset();
});

describe('key lifecycle', () => {
  test('no key exists before one is created', () => {
    expect(hasSigningKey()).toBe(false);
  });

  test('creating a key returns the PEM and its security level', async () => {
    const result = await createSigningKey();

    expect(result.publicKeyPem).toContain('BEGIN PUBLIC KEY');
    expect(result.securityLevel).toBe('TRUSTED_ENVIRONMENT');
    expect(result.hardwareBacked).toBe(true);
    expect(hasSigningKey()).toBe(true);
  });

  test('a software-backed key is reported as not hardware-backed', async () => {
    // The emulator path. The server decides whether to accept it; the app's
    // job is to report accurately rather than round up.
    __setSecurityLevel('SOFTWARE');

    const result = await createSigningKey();

    expect(result.securityLevel).toBe('SOFTWARE');
    expect(result.hardwareBacked).toBe(false);
  });

  test('deleting the key removes it', async () => {
    await createSigningKey();

    expect(deleteSigningKey()).toBe(true);
    expect(hasSigningKey()).toBe(false);
  });

  test('reading the security level without a key throws rather than guessing', () => {
    expect(() => getSecurityLevel()).toThrow(/No signing key/);
  });
});

describe('isHardwareBacked', () => {
  test.each([
    ['STRONGBOX', true],
    ['TRUSTED_ENVIRONMENT', true],
    ['SOFTWARE', false],
    ['', false],
    ['ANYTHING_ELSE', false],
  ])('%s -> %s', (level, expected) => {
    expect(isHardwareBacked(level)).toBe(expected);
  });
});

describe('signAttendance', () => {
  test('signs exactly the canonical payload the server will verify', async () => {
    // The single most important assertion in this file. If the wrapper signed
    // anything other than canonicalize(record) — a JSON blob, a digest, a
    // reordered form — every signature would fail server-side for reasons
    // invisible at the call site.
    await createSigningKey();

    await signAttendance(RECORD);

    expect(__signedPayloads).toHaveLength(1);
    expect(__signedPayloads[0]).toBe(canonicalize(RECORD));
  });

  test('the signed payload carries the monotonic timestamp and boot id', async () => {
    await createSigningKey();

    await signAttendance(RECORD);

    expect(__signedPayloads[0]).toContain('monotonic_timestamp=86400000');
    expect(__signedPayloads[0]).toContain('boot_id=bc7');
  });

  test('a changed field produces a different signed payload', async () => {
    await createSigningKey();

    await signAttendance(RECORD);
    await signAttendance({...RECORD, time_in: 1789200000000 - 7_200_000});

    expect(__signedPayloads[0]).not.toBe(__signedPayloads[1]);
  });

  test('signing without a key rejects', async () => {
    await expect(signAttendance(RECORD)).rejects.toThrow(/No signing key/);
  });

  test('a native signing failure propagates rather than resolving empty', async () => {
    // A silently-empty signature would be stored and rejected later by the
    // server with no indication of where it went wrong.
    await createSigningKey();
    __failNextSign();

    await expect(signAttendance(RECORD)).rejects.toThrow(/sign_failed/);
  });

  test('an invalid record is refused before reaching the native layer', async () => {
    await createSigningKey();

    await expect(
      signAttendance({...RECORD, device_id: 'x\nprev_hash=deadbeef'}),
    ).rejects.toThrow(/line break/);

    expect(__signedPayloads).toHaveLength(0);
  });
});

describe('key alias', () => {
  test('is versioned, so a future format change can rotate keys deliberately', () => {
    expect(SIGNING_KEY_ALIAS).toMatch(/\.v\d+$/);
  });
});
