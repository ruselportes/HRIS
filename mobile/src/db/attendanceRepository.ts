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
import {chainEpoch, loadCredentials} from '../crypto/deviceCredentials';
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

/**
 * The chain tip: hmac_hash of the most recent event in the CURRENT binding's
 * chain.
 *
 * Scoped by epoch, not "the latest event ever". Binding resets the server's tip
 * to null; if this looked across all history, the first event after a rebind
 * would link to pre-rebind events, the server would reject it, and the device
 * could never sync again — breaking rebind, the recovery path for a broken
 * chain.
 */
async function currentChainTip(epoch: string): Promise<string | null> {
  const db = await getDatabase();
  const result = await db.execute(
    `SELECT hmac_hash FROM attendance_events
      WHERE chain_epoch = ?
      ORDER BY event_id DESC LIMIT 1;`,
    [epoch],
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
  const epoch = chainEpoch(credentials);
  const prevHash = await currentChainTip(epoch);

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
       boot_id, boot_id_system_backed, chain_epoch, device_id, prev_hash,
       hmac_hash, ecdsa_signature, override_flag, captured_at, sync_status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending');`,
    [
      payload.employee_id,
      payload.crew_id,
      payload.date,
      payload.status,
      payload.time_in,
      payload.monotonic_timestamp,
      payload.boot_id,
      clock.bootIdSystemBacked ? 1 : 0,
      epoch,
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

/* ------------------------------------------------------------------------ *
 * Sync (Phase 6)
 * ------------------------------------------------------------------------ */

/**
 * sync_status values on attendance_events:
 *   pending  — captured, not yet confirmed by the server
 *   synced   — accepted and trusted
 *   flagged  — accepted but time-suspect, sent for HR review
 *   rejected — refused; failed verification or orphaned by an earlier refusal
 *
 * "Kept until confirmed by server" (the Sync Queue prototype): nothing is
 * deleted on sync. Rows only change status.
 */
export type EventSyncStatus = 'pending' | 'synced' | 'flagged' | 'rejected';

/**
 * Pending events for the CURRENT binding, in chain order.
 *
 * Order is chain order (event_id), and must never be re-sorted: the server
 * walks the batch expecting each event to link to the one before it.
 */
export async function listPendingEvents(epoch: string): Promise<any[]> {
  const db = await getDatabase();
  const result = await db.execute(
    `SELECT * FROM attendance_events
      WHERE sync_status = 'pending' AND chain_epoch = ?
      ORDER BY event_id;`,
    [epoch],
  );

  return result.rows as any[];
}

/** Apply the server's verdict to each event, matched by hmac_hash. */
export async function applyEventOutcomes(
  outcomes: {hmacHash: string; status: 'accepted' | 'flagged' | 'rejected'}[],
): Promise<void> {
  if (outcomes.length === 0) {
    return;
  }

  const db = await getDatabase();

  await db.transaction(async tx => {
    for (const outcome of outcomes) {
      const status: EventSyncStatus =
        outcome.status === 'accepted' ? 'synced' : outcome.status;

      await tx.execute(
        'UPDATE attendance_events SET sync_status = ? WHERE hmac_hash = ?;',
        [status, outcome.hmacHash],
      );
    }
  });
}

/**
 * Reconcile against the server's real chain tip: every pending event up to and
 * including the one whose hash the server already holds was accepted, even if
 * the device never heard back.
 *
 * This is the lost-response recovery. The server commits a batch, the reply is
 * lost, and a blind retry would fail every event as prev_hash_mismatch —
 * marking genuinely accepted records as rejected. Asking the server where it
 * is first avoids that.
 *
 * Marked `synced`, not flagged: the server does not report per-event verdicts
 * here, and a flagged event is audit-logged server-side regardless of how the
 * device labels it. HR's view is authoritative; this only stops the device
 * resending what the server already has.
 *
 * Returns how many events were reconciled.
 */
export async function markSyncedThrough(
  epoch: string,
  serverTipHash: string,
): Promise<number> {
  const db = await getDatabase();

  const tip = await db.execute(
    `SELECT event_id FROM attendance_events
      WHERE chain_epoch = ? AND hmac_hash = ?
      LIMIT 1;`,
    [epoch, serverTipHash],
  );

  // The server's tip is not in this device's current chain — nothing to
  // reconcile. (A tip from a previous binding, or a chain this device never
  // produced; either way, not ours to mark.)
  if (tip.rows.length === 0) {
    return 0;
  }

  const upTo = (tip.rows[0] as any).event_id;

  const result = await db.execute(
    `UPDATE attendance_events
        SET sync_status = 'synced'
      WHERE chain_epoch = ? AND event_id <= ? AND sync_status = 'pending';`,
    [epoch, upTo],
  );

  return result.rowsAffected ?? 0;
}

/** Record one attempt against the events it covered (ERD tbl_attendance_sync_queue). */
export async function recordSyncAttempt(
  eventIds: number[],
  deviceId: string,
  status: 'synced' | 'failed',
): Promise<void> {
  if (eventIds.length === 0) {
    return;
  }

  const db = await getDatabase();
  const now = Date.now();

  await db.transaction(async tx => {
    for (const eventId of eventIds) {
      await tx.execute(
        `INSERT INTO attendance_sync_queue (event_id, device_id, queued_at, synced_at, sync_status)
         VALUES (?, ?, ?, ?, ?);`,
        [eventId, deviceId, now, status === 'synced' ? now : null, status],
      );
    }
  });
}

export async function recordLastSuccessfulSync(at: number): Promise<void> {
  const db = await getDatabase();
  await db.execute(
    "INSERT INTO app_settings (key, value) VALUES ('last_successful_sync', ?) " +
      'ON CONFLICT(key) DO UPDATE SET value = excluded.value;',
    [String(at)],
  );
}

export type SyncQueueRow = {
  eventId: number;
  employeeName: string;
  status: string;
  timeIn: number | null;
  capturedAt: number;
  syncStatus: EventSyncStatus;
  overrideFlag: boolean;
};

export type SyncSummary = {
  pending: number;
  synced: number;
  flagged: number;
  rejected: number;
  lastSuccessfulSync: number | null;
  rows: SyncQueueRow[];
};

/**
 * Everything the Sync Queue screen shows.
 *
 * Only the latest event per employee+day is listed, matching what the foreman
 * thinks of as "a record" — an Undo followed by a re-tap is one person, not
 * three rows. Failed sorts first ("Failed first", per the prototype), then
 * oldest first within each group ("As marked, oldest first").
 *
 * Names come from the roster cache so a failure names the person rather than a
 * hash ("A failure names the person").
 */
export async function getSyncSummary(epoch: string): Promise<SyncSummary> {
  const db = await getDatabase();

  const counts = await db.execute(
    `SELECT sync_status, COUNT(*) AS n FROM attendance_events
      WHERE chain_epoch = ? GROUP BY sync_status;`,
    [epoch],
  );

  const tally: Record<string, number> = {};
  for (const row of counts.rows as any[]) {
    tally[row.sync_status] = row.n;
  }

  const rows = await db.execute(
    `SELECT e.event_id, e.status, e.time_in, e.captured_at, e.sync_status,
            e.override_flag, r.first_name, r.last_name
       FROM attendance_events e
       JOIN (
         SELECT employee_id, date, MAX(event_id) AS latest_id
           FROM attendance_events WHERE chain_epoch = ?
          GROUP BY employee_id, date
       ) newest ON newest.latest_id = e.event_id
       LEFT JOIN crew_roster_cache r ON r.employee_id = e.employee_id
      ORDER BY CASE e.sync_status WHEN 'rejected' THEN 0 ELSE 1 END, e.event_id;`,
    [epoch],
  );

  const last = await db.execute(
    "SELECT value FROM app_settings WHERE key = 'last_successful_sync';",
  );

  return {
    pending: tally.pending ?? 0,
    synced: tally.synced ?? 0,
    flagged: tally.flagged ?? 0,
    rejected: tally.rejected ?? 0,
    lastSuccessfulSync:
      last.rows.length === 0 ? null : Number((last.rows[0] as any).value),
    rows: (rows.rows as any[]).map(row => ({
      eventId: row.event_id,
      employeeName:
        row.last_name && row.first_name
          ? `${row.last_name}, ${row.first_name}`
          : `Employee #${row.event_id}`,
      status: row.status,
      timeIn: row.time_in,
      capturedAt: row.captured_at,
      syncStatus: row.sync_status,
      overrideFlag: !!row.override_flag,
    })),
  };
}
