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
import {getDatabase, resetDatabaseInstanceForTests} from '../database';
import {
  DeviceNotBoundError,
  clearRosterCache,
  countPendingEvents,
  getShiftConfig,
  hasRollCallStarted,
  recordManualTime,
  recordShiftCredit,
  recordStatus,
  saveLateStartChoice,
  saveRosterCache,
  undoAttendance,
} from '../attendanceRepository';
import {DEFAULT_SHIFT, shiftStartMs, siteTimeMs} from '../shiftRules';
import {todayLocalDate} from '../attendanceLogic';
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

/**
 * The event INSERT's bound values keyed by column name, parsed from the SQL's
 * own column list.
 *
 * Positional destructuring broke the moment chain_epoch was added — every later
 * index shifted. Reading names from the statement means adding a column cannot
 * silently re-point an assertion at the wrong value.
 */
function eventRow(): Record<string, any> {
  const insert = eventInsert();
  if (!insert) {
    throw new Error('No INSERT INTO attendance_events was issued.');
  }

  const columnList = insert.sql.match(/INSERT INTO attendance_events\s*\(([^)]*)\)/);
  if (!columnList) {
    throw new Error('Could not parse the column list from the event INSERT.');
  }

  const columns = columnList[1].split(',').map(column => column.trim());

  // sync_status is a SQL literal ('pending'), not a bound parameter, so it has
  // no entry in params — the bound columns are everything before it.
  const bound = columns.filter(column => column !== 'sync_status');

  return Object.fromEntries(bound.map((column, i) => [column, insert.params[i]]));
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

    const row = eventRow();

    // Rebuild the payload from the row that was written and confirm the
    // signature and HMAC were taken over precisely that.
    const payload = {
      employee_id: row.employee_id,
      crew_id: row.crew_id,
      date: row.date,
      event_type: row.event_type,
      status: row.status,
      time_in: row.time_in,
      time_out: row.time_out,
      captured_at: row.captured_at,
      override_type: row.override_type,
      time_out_type: row.time_out_type,
      monotonic_timestamp: row.monotonic_timestamp,
      boot_id: row.boot_id,
      device_id: row.device_id,
      prev_hash: row.prev_hash,
    };

    // Stored with the version it was signed under, so it is resent as that.
    expect(row.payload_version).toBe('v3');
    expect(row.event_type).toBe('roll_call');
    expect(__signedPayloads).toHaveLength(1);
    expect(__signedPayloads[0]).toBe(canonicalize(payload));
    expect(row.hmac_hash).toBe(computeHmac(payload, hmacKeyFromBase64(KEY_BASE64)));
    expect(row.ecdsa_signature).toBe(`mock-signature:${canonicalize(payload).length}`);
  });

  /*
   * The server refuses an ordinary tap whose time_in drifts from captured_at.
   * They used to be two separate Date.now() reads with awaits between them, so
   * they are now required to be the same value.
   */
  test('an ordinary tap signs time_in equal to the captured tap time', async () => {
    await recordStatus(42, 7, 'present');

    const row = eventRow();

    expect(row.time_in).toBe(row.captured_at);
    expect(row.override_type).toBeNull();
  });

  test('stamps the event with the current binding epoch', async () => {
    // Scoping the chain to its binding is what lets a rebind restart it.
    await recordStatus(42, 7, 'present');

    expect(eventRow().chain_epoch).toBe(
      deviceCredentials.chainEpoch({deviceId: DEVICE_ID, hmacKeyBase64: KEY_BASE64}),
    );
  });

  test('looks up the chain tip within the current epoch only', async () => {
    // Regression for the rebind bug: an unscoped "latest event ever" tip made a
    // rebound device link to pre-rebind history and never sync again.
    await recordStatus(42, 7, 'present');

    const tipQuery = dbCalls().find(call =>
      /SELECT hmac_hash FROM attendance_events/.test(call.sql),
    );

    expect(tipQuery?.sql).toMatch(/WHERE chain_epoch = \?/);
    expect(tipQuery?.params).toEqual([
      deviceCredentials.chainEpoch({deviceId: DEVICE_ID, hmacKeyBase64: KEY_BASE64}),
    ]);
  });

  test('the first event links to a null previous hash', async () => {
    // The mock returns no rows, i.e. an empty log — the device's first event.
    await recordStatus(42, 7, 'present');

    expect(eventRow().prev_hash).toBeNull();
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

    const row = eventRow();
    expect(row.time_in).toBeNull();
    expect(row.monotonic_timestamp).toBe(900_000);
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

/*
 * Late start (Phase 7 — UC-05, STD TC-04). The server re-checks every one of
 * these on sync; what is tested here is that the phone signs what the server
 * expects, so a legitimate override is not refused.
 */
describe('late start', () => {
  /** Answer app_settings reads from a fake store; everything else stays empty. */
  async function withSettings(settings: Record<string, string>): Promise<void> {
    const db = await getDatabase();

    (db.execute as jest.Mock).mockImplementation(async (sql: string, params: any[] = []) => {
      if (/SELECT value FROM app_settings WHERE key = \?/.test(sql) && params[0] in settings) {
        return {rows: [{value: settings[params[0]]}], rowsAffected: 0};
      }

      return {rows: [], rowsAffected: 0};
    });
  }

  const CACHED_SHIFT = {
    start: '06:30',
    late_override_grace_minutes: 10,
    timezone: 'Asia/Manila',
    utc_offset_minutes: 480,
  };

  test('shift credit signs Present at exactly the cached shift start', async () => {
    await withSettings({shift_config: JSON.stringify(CACHED_SHIFT)});

    await recordShiftCredit(42, 7);

    const row = eventRow();
    expect(row.status).toBe('present');
    expect(row.override_type).toBe('shift_credit');
    expect(row.time_in).toBe(shiftStartMs(todayLocalDate(), CACHED_SHIFT));
    expect(__signedPayloads[0]).toContain('override_type=shift_credit');
  });

  test('the credit still records when the tap really happened', async () => {
    const tappedAt = Date.now();
    jest.spyOn(Date, 'now').mockReturnValue(tappedAt);

    await recordShiftCredit(42, 7);

    // Both instants are kept: HR sees the credit and the real tap side by side.
    expect(eventRow().captured_at).toBe(tappedAt);
    expect(eventRow().time_in).not.toBe(tappedAt);
  });

  test('manual time signs the stated arrival as manual_time', async () => {
    const at = siteTimeMs(todayLocalDate(), 7, 45, DEFAULT_SHIFT);

    await recordManualTime(42, 7, 'late', at);

    const row = eventRow();
    expect(row.status).toBe('late');
    expect(row.time_in).toBe(at);
    expect(row.override_type).toBe('manual_time');
  });

  test('shift rules fall back to the server defaults before the first fetch', async () => {
    await withSettings({});
    expect(await getShiftConfig()).toEqual(DEFAULT_SHIFT);
  });

  test('a malformed cached config falls back rather than blocking roll call', async () => {
    await withSettings({shift_config: '{"start":"seven"}'});
    expect(await getShiftConfig()).toEqual(DEFAULT_SHIFT);

    await withSettings({shift_config: 'not json'});
    expect(await getShiftConfig()).toEqual(DEFAULT_SHIFT);
  });

  test('roll call counts as started once anyone on the crew is marked today', async () => {
    const db = await getDatabase();
    (db.execute as jest.Mock).mockResolvedValueOnce({rows: [{1: 1}], rowsAffected: 0});

    expect(await hasRollCallStarted(7, '2026-09-12')).toBe(true);

    const query = dbCalls().at(-1)!;
    expect(query.sql).toMatch(/WHERE crew_id = \? AND date = \?/);
    expect(query.params).toEqual([7, '2026-09-12']);
  });

  test("saving today's choice clears other days but keeps other crews' today", async () => {
    await saveLateStartChoice(7, 'credit', '2026-09-12');

    const cleanup = dbCalls().find(call => /DELETE FROM app_settings/.test(call.sql));
    expect(cleanup?.params).toEqual(['late_start:%:2026-09-12']);

    const write = dbCalls().find(
      call => /INSERT INTO app_settings/.test(call.sql) && call.params[0] === 'late_start:7:2026-09-12',
    );
    expect(JSON.parse(write!.params[1]).mode).toBe('credit');
  });
});

/* Acting foreman (Phase 7 — UC-06, TC-05). */
describe('roster ownership', () => {
  test('a roster handed over is forgotten, but recorded attendance is kept', async () => {
    const db = await getDatabase();
    const executed: string[] = [];
    (db.transaction as jest.Mock).mockImplementationOnce(async (fn: any) => {
      await fn({execute: jest.fn(async (sql: string) => executed.push(sql))});
    });

    await clearRosterCache();

    expect(executed.some(sql => /DELETE FROM crew_roster_cache/.test(sql))).toBe(true);
    expect(executed.some(sql => /attendance_events/.test(sql))).toBe(false);
  });

  test('an acting cover is cached with the roster', async () => {
    const db = await getDatabase();
    const executed: {sql: string; params: any[]}[] = [];
    (db.transaction as jest.Mock).mockImplementationOnce(async (fn: any) => {
      await fn({execute: jest.fn(async (sql: string, params: any[] = []) => executed.push({sql, params}))});
    });

    const cover = {until: '2026-09-12T15:59:59+00:00', regularForemanName: 'Dela Cruz, Ronel B.'};
    await saveRosterCache(7, 'Formwork crew B', 'Site 07', [], cover);

    const write = executed.find(call => /'roster_acting'/.test(call.sql));
    expect(JSON.parse(write!.params[0])).toEqual(cover);
  });

  test('pending records are counted per binding', async () => {
    const db = await getDatabase();
    (db.execute as jest.Mock).mockResolvedValueOnce({rows: [{n: 3}], rowsAffected: 0});

    expect(await countPendingEvents('epoch-f1')).toBe(3);
    expect(dbCalls().at(-1)!.params).toEqual(['epoch-f1']);
  });
});
