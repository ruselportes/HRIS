/**
 * Capture-path wiring tests (Phase 5).
 *
 * The individual pieces — payload, HMAC, clock, signer — are unit-tested
 * elsewhere. What this file covers is the wiring between them, which is where
 * the ordering matters: capture the clock, chain from the current tip, sign
 * what was chained, then write. A mistake here produces rows that look
 * plausible locally and are rejected server-side.
 *
 * Asserts against the SQL actually issued, via op-sqlite's jest mock.
 *
 * @format
 */

import {open} from '@op-engineering/op-sqlite';
import {__reset as resetSigner, __signedPayloads} from '../../native/__mocks__/NativeHrisTeeSigner';
import {
  __reset as resetClock,
  __setBootId,
  __setElapsedRealtime,
} from '../../native/__mocks__/NativeHrisMonotonicClock';
import {canonicalize} from '../../crypto/payload';
import {computeHmac, hmacKeyFromBase64} from '../../crypto/hashChain';
import {resetDatabaseInstanceForTests} from '../database';
import {DeviceNotBoundError, recordStatus, undoAttendance} from '../attendanceRepository';
import * as deviceCredentials from '../../crypto/deviceCredentials';
import {createSigningKey} from '../../crypto/teeSigner';

const KEY_BASE64 = 'c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=';
const DEVICE_ID = 'dev-test-0001';

/** The fake db instance database.ts cached, so its calls can be inspected. */
function dbCalls(): {sql: string; params: any[]}[] {
  const instance = (open as jest.Mock).mock.results.at(-1)?.value;

  return (instance.execute as jest.Mock).mock.calls.map(
    ([sql, params]: [string, any[]]) => ({sql, params: params ?? []}),
  );
}

function eventInsert(): {sql: string; params: any[]} | undefined {
  return dbCalls().find(call => call.sql.includes('INSERT INTO attendance_events'));
}

beforeEach(async () => {
  jest.clearAllMocks();
  resetDatabaseInstanceForTests();
  resetClock();
  resetSigner();

  jest
    .spyOn(deviceCredentials, 'loadCredentials')
    .mockResolvedValue({deviceId: DEVICE_ID, hmacKeyBase64: KEY_BASE64});

  await createSigningKey();
});

afterEach(() => {
  jest.restoreAllMocks();
});

describe('recordStatus', () => {
  test('writes the clock reading the native module reported, not a wall clock', async () => {
    // The whole point of layer 1: monotonic_timestamp must come from
    // elapsedRealtime, never from Date.now().
    __setElapsedRealtime(555_000);
    __setBootId('bc9');

    await recordStatus(42, 7, 'present');

    const insert = eventInsert();
    expect(insert).toBeDefined();
    expect(insert!.params).toContain(555_000);
    expect(insert!.params).toContain('bc9');
  });

  test('signs exactly what it writes', async () => {
    __setElapsedRealtime(555_000);
    __setBootId('bc9');

    await recordStatus(42, 7, 'present');

    const insert = eventInsert()!;
    const [
      employeeId,
      crewId,
      date,
      status,
      timeIn,
      monotonic,
      bootId,
      ,
      deviceId,
      prevHash,
      hmacHash,
      signature,
    ] = insert.params;

    // Rebuild the payload from the row that was written and confirm the
    // signature and HMAC were taken over precisely that.
    const payload = {
      employee_id: employeeId,
      crew_id: crewId,
      date,
      status,
      time_in: timeIn,
      monotonic_timestamp: monotonic,
      boot_id: bootId,
      device_id: deviceId,
      prev_hash: prevHash,
    };

    expect(__signedPayloads).toHaveLength(1);
    expect(__signedPayloads[0]).toBe(canonicalize(payload));
    expect(hmacHash).toBe(computeHmac(payload, hmacKeyFromBase64(KEY_BASE64)));
    expect(signature).toBe(`mock-signature:${canonicalize(payload).length}`);
  });

  test('the first event links to a null previous hash', async () => {
    // The mock returns no rows, i.e. an empty log — the device's first event.
    await recordStatus(42, 7, 'present');

    expect(eventInsert()!.params[9]).toBeNull();
  });

  test('captures the clock before signing, so signing latency cannot alter it', async () => {
    // Ordering assertion: the signed payload already carries the monotonic
    // value, which is only possible if capture preceded signing.
    __setElapsedRealtime(777_000);

    await recordStatus(42, 7, 'late');

    expect(__signedPayloads[0]).toContain('monotonic_timestamp=777000');
  });

  test('enqueues a sync row for the event it just wrote', async () => {
    await recordStatus(42, 7, 'present');

    const queueInsert = dbCalls().find(call =>
      call.sql.includes('INSERT INTO attendance_sync_queue'),
    );

    expect(queueInsert).toBeDefined();
    expect(queueInsert!.params).toContain(DEVICE_ID);
  });

  test('absent carries no time_in but still carries the monotonic reading', async () => {
    __setElapsedRealtime(900_000);

    await recordStatus(42, 7, 'absent');

    const insert = eventInsert()!;
    expect(insert.params[4]).toBeNull(); // time_in
    expect(insert.params[5]).toBe(900_000); // monotonic_timestamp
  });
});

describe('undoAttendance', () => {
  test('appends a reverting event rather than deleting anything', async () => {
    await undoAttendance(42, 7);

    const calls = dbCalls();

    expect(eventInsert()).toBeDefined();
    expect(calls.some(call => /DELETE FROM attendance_events/i.test(call.sql))).toBe(false);
    expect(calls.some(call => /UPDATE attendance_events/i.test(call.sql))).toBe(false);
  });

  test('the reverting event is signed like any other', async () => {
    await undoAttendance(42, 7);

    // A correction that was not itself attested would be an unauditable hole.
    expect(__signedPayloads).toHaveLength(1);
    expect(__signedPayloads[0]).toContain('status=pending');
  });
});

describe('unbound device', () => {
  test('refuses to capture rather than writing an unsigned row', async () => {
    jest.spyOn(deviceCredentials, 'loadCredentials').mockResolvedValue(null);

    await expect(recordStatus(42, 7, 'present')).rejects.toThrow(DeviceNotBoundError);

    // Nothing written: an unsigned row would be rejected on sync anyway, so
    // writing one would only lose the foreman's work silently.
    expect(eventInsert()).toBeUndefined();
    expect(__signedPayloads).toHaveLength(0);
  });
});
