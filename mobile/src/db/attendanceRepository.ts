/**
 * SQLite-backed repository (op-sqlite).
 *
 * As of Phase 5 this is an append-only signed event log, not a mutable table.
 * Every tap writes a new chained, signed row; nothing is updated or deleted,
 * because a hash chain cannot tolerate rewriting history — editing a chained
 * row invalidates its own HMAC and orphans every row after it, which is the
 * property STD TC-02 relies on. Undo is therefore an appended event, not a
 * deletion.
 *
 * Current UI state is derived (latest event per employee+date) rather than
 * stored, so there is a single source of truth instead of two representations
 * that can drift apart.
 *
 * @format
 */

import {getDatabase} from './database';
import {
  AttendanceRecord,
  AttendanceStatus,
  blankRecordFor,
  selectStatus,
  todayLocalDate,
  undoStatus,
} from './attendanceLogic';
import {loadCredentials} from '../crypto/deviceCredentials';
import {captureClock} from '../crypto/monotonicClock';
import {computeHmac, hmacKeyFromBase64} from '../crypto/hashChain';
import {signAttendance} from '../crypto/teeSigner';
import {AttendancePayloadRecord} from '../crypto/payload';

export interface RosterMember {
  employeeId: number;
  employeeCode: string | null;
  firstName: string;
  lastName: string;
  tradeSkill: string | null;
}

export interface CachedCrew {
  crewId: number;
  crewName: string;
  siteName: string | null;
  cachedAt: number;
  members: RosterMember[];
}

/** Raised when capture is attempted before the device has been bound. */
export class DeviceNotBoundError extends Error {
  constructor() {
    super(
      'This device is not bound. Complete device binding before recording attendance.',
    );
    this.name = 'DeviceNotBoundError';
  }
}

/** Replaces the whole cached roster with a fresh fetch from GET /api/me/crew. */
export async function saveRosterCache(
  crewId: number,
  crewName: string,
  siteName: string | null,
  members: RosterMember[],
): Promise<void> {
  const db = await getDatabase();
  const cachedAt = Date.now();

  await db.transaction(async tx => {
    await tx.execute('DELETE FROM crew_roster_cache;');

    for (const member of members) {
      await tx.execute(
        `INSERT INTO crew_roster_cache
          (employee_id, employee_code, first_name, last_name, trade_skill, crew_id, crew_name, site_name, cached_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);`,
        [
          member.employeeId,
          member.employeeCode,
          member.firstName,
          member.lastName,
          member.tradeSkill,
          crewId,
          crewName,
          siteName,
          cachedAt,
        ],
      );
    }
  });
}

export async function getCachedCrew(): Promise<CachedCrew | null> {
  const db = await getDatabase();
  const result = await db.execute(
    'SELECT * FROM crew_roster_cache ORDER BY last_name;',
  );

  if (result.rows.length === 0) {
    return null;
  }

  const members: RosterMember[] = result.rows.map((row: any) => ({
    employeeId: row.employee_id,
    employeeCode: row.employee_code,
    firstName: row.first_name,
    lastName: row.last_name,
    tradeSkill: row.trade_skill,
  }));

  const first = result.rows[0] as any;

  return {
    crewId: first.crew_id,
    crewName: first.crew_name,
    siteName: first.site_name,
    cachedAt: first.cached_at,
    members,
  };
}

function rowToRecord(row: any): AttendanceRecord {
  return {
    employeeId: row.employee_id,
    date: row.date,
    status: row.status as AttendanceStatus,
    timeIn: row.time_in,
    overrideFlag: !!row.override_flag,
  };
}

/** The chain tip: hmac_hash of the most recent event this device produced. */
async function currentChainTip(): Promise<string | null> {
  const db = await getDatabase();
  const result = await db.execute(
    'SELECT hmac_hash FROM attendance_events ORDER BY event_id DESC LIMIT 1;',
  );

  return result.rows.length === 0 ? null : (result.rows[0] as any).hmac_hash;
}

/** Latest event for one employee on a date, or a blank record if none. */
async function latestEvent(
  employeeId: number,
  date: string,
): Promise<AttendanceRecord> {
  const db = await getDatabase();
  const result = await db.execute(
    `SELECT * FROM attendance_events
      WHERE employee_id = ? AND date = ?
      ORDER BY event_id DESC LIMIT 1;`,
    [employeeId, date],
  );

  return result.rows.length === 0
    ? blankRecordFor(employeeId, date)
    : rowToRecord(result.rows[0]);
}

export async function getTodayAttendance(
  employeeId: number,
): Promise<AttendanceRecord> {
  return latestEvent(employeeId, todayLocalDate());
}

/**
 * Current state for every employee marked today — the latest event each.
 * Earlier events for the same employee remain in the log; they are history,
 * not current state.
 */
export async function listTodayAttendance(): Promise<
  Map<number, AttendanceRecord>
> {
  const db = await getDatabase();
  const date = todayLocalDate();

  const result = await db.execute(
    `SELECT e.* FROM attendance_events e
      JOIN (
        SELECT employee_id, MAX(event_id) AS latest_id
        FROM attendance_events WHERE date = ?
        GROUP BY employee_id
      ) newest ON newest.latest_id = e.event_id;`,
    [date],
  );

  const map = new Map<number, AttendanceRecord>();
  for (const row of result.rows as any[]) {
    map.set(row.employee_id, rowToRecord(row));
  }

  return map;
}

/**
 * Append one chained, signed event.
 *
 * Order matters and is deliberate: capture the clock first (synchronously, at
 * the tap), then chain, then sign. Signing is the slow step and happens last,
 * over values already fixed — so its latency cannot influence what was
 * attested.
 */
async function appendEvent(
  record: AttendanceRecord,
  crewId: number,
): Promise<AttendanceRecord> {
  const credentials = await loadCredentials();

  if (credentials === null) {
    // Refuse rather than writing an unsigned row. The server would reject it
    // anyway, so a fallback would only lose the foreman's work silently.
    throw new DeviceNotBoundError();
  }

  const clock = captureClock();
  const prevHash = await currentChainTip();

  const payload: AttendancePayloadRecord = {
    employee_id: record.employeeId,
    crew_id: crewId,
    date: record.date,
    status: record.status,
    time_in: record.timeIn,
    monotonic_timestamp: Math.round(clock.monotonicMs),
    boot_id: clock.bootId,
    device_id: credentials.deviceId,
    prev_hash: prevHash,
  };

  const hmacHash = computeHmac(
    payload,
    hmacKeyFromBase64(credentials.hmacKeyBase64),
  );

  // Signed before insert, not after: a row written first and signed second
  // could be left permanently unsignable if signing throws in between.
  const signature = await signAttendance(payload);

  const db = await getDatabase();

  const insert = await db.execute(
    `INSERT INTO attendance_events
      (employee_id, crew_id, date, status, time_in, monotonic_timestamp,
       boot_id, boot_id_system_backed, device_id, prev_hash, hmac_hash,
       ecdsa_signature, override_flag, captured_at, sync_status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending');`,
    [
      payload.employee_id,
      payload.crew_id,
      payload.date,
      payload.status,
      payload.time_in,
      payload.monotonic_timestamp,
      payload.boot_id,
      clock.bootIdSystemBacked ? 1 : 0,
      payload.device_id,
      payload.prev_hash,
      hmacHash,
      signature,
      record.overrideFlag ? 1 : 0,
      clock.wallClockMs,
    ],
  );

  /*
   * insertId from the INSERT itself, rather than a follow-up
   * "SELECT event_id ORDER BY event_id DESC LIMIT 1". That query was both
   * unnecessary and unsafe: it indexed rows[0] unguarded, so an unexpectedly
   * empty result would throw after the event had already been written,
   * leaving a committed event with no queue row and the foreman's tap
   * apparently failed when it had in fact been recorded.
   */
  await db.execute(
    `INSERT INTO attendance_sync_queue (event_id, device_id, queued_at, sync_status)
     VALUES (?, ?, ?, 'pending');`,
    [insert.insertId ?? null, credentials.deviceId, Date.now()],
  );

  return record;
}

/**
 * Foreman taps Present/Late/Absent. Appends a new signed event — no network
 * call in this path, which is what keeps roll call offline-capable.
 */
export async function recordStatus(
  employeeId: number,
  crewId: number,
  status: 'present' | 'late' | 'absent',
): Promise<AttendanceRecord> {
  const date = todayLocalDate();
  const current = await latestEvent(employeeId, date);

  return appendEvent(selectStatus(current, status, Date.now()), crewId);
}

/**
 * Undo — appends a reverting event rather than deleting anything. The original
 * event stays in the log and in the chain, which is what makes the correction
 * itself auditable rather than invisible.
 */
export async function undoAttendance(
  employeeId: number,
  crewId: number,
): Promise<AttendanceRecord> {
  const date = todayLocalDate();
  const current = await latestEvent(employeeId, date);

  return appendEvent(undoStatus(current), crewId);
}

/** Pending events in chain order — what Phase 6's sync engine will drain. */
export async function listPendingEvents(): Promise<any[]> {
  const db = await getDatabase();
  const result = await db.execute(
    "SELECT * FROM attendance_events WHERE sync_status = 'pending' ORDER BY event_id;",
  );

  return result.rows as any[];
}
