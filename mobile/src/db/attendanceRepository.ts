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
 * Current UI state is derived rather than stored, so there is a single source
 * of truth instead of two representations that can drift apart: a worker's
 * day is their latest roll-call event, closed by any time-out event newer
 * than it.
 *
 * @format
 */

import {getDatabase} from './database';
import {
  AttendanceRecord,
  AttendanceStatus,
  NO_TIME_OUT,
  TimeOut,
  blankRecordFor,
  isOnSite,
  selectStatus,
  todayLocalDate,
  undoStatus,
} from './attendanceLogic';
import {chainEpoch, loadCredentials} from '../crypto/deviceCredentials';
import {captureClock} from '../crypto/monotonicClock';
import {computeHmac, hmacKeyFromBase64} from '../crypto/hashChain';
import {
  DEFAULT_SHIFT,
  ShiftConfig,
  canCloseShift,
  creditAtShiftStart,
  formatSiteTime,
  shiftEndMs,
  withManualTime,
} from './shiftRules';
import {signAttendance} from '../crypto/teeSigner';
import {
  AttendancePayloadRecord,
  OverrideType,
  PAYLOAD_VERSION,
  TimeOutType,
} from '../crypto/payload';

export interface RosterMember {
  employeeId: number;
  employeeCode: string | null;
  firstName: string;
  lastName: string;
  tradeSkill: string | null;
}

/** Set when the signed-in foreman is covering for another (Phase 7, UC-06). */
export interface ActingCover {
  /** ISO 8601, when the cover ends on its own. */
  until: string;
  regularForemanName: string | null;
}

export interface CachedCrew {
  crewId: number;
  crewName: string;
  siteName: string | null;
  cachedAt: number;
  members: RosterMember[];
  acting: ActingCover | null;
}

/**
 * A record as roll call shows it: what was credited, when it was tapped, and
 * how the day ended.
 */
export type TodayRecord = AttendanceRecord & TimeOut & {capturedAt: number | null};

/** Raised when capture is attempted before the device has been bound. */
export class DeviceNotBoundError extends Error {
  constructor() {
    super(
      'This device is not bound. Complete device binding before recording attendance.',
    );
    this.name = 'DeviceNotBoundError';
  }
}

/**
 * A time-out the server would refuse, caught before it is signed. Its message
 * is written for the foreman.
 */
export class TimeOutRefusedError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'TimeOutRefusedError';
  }
}

/** Replaces the whole cached roster with a fresh fetch from GET /api/me/crew. */
export async function saveRosterCache(
  crewId: number,
  crewName: string,
  siteName: string | null,
  members: RosterMember[],
  acting: ActingCover | null = null,
): Promise<void> {
  const db = await getDatabase();
  const cachedAt = Date.now();

  await db.transaction(async tx => {
    await tx.execute('DELETE FROM crew_roster_cache;');
    await tx.execute(
      "INSERT INTO app_settings (key, value) VALUES ('roster_acting', ?) " +
        'ON CONFLICT(key) DO UPDATE SET value = excluded.value;',
      [acting === null ? '' : JSON.stringify(acting)],
    );

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

/**
 * Forget the roster when the server says this foreman has no crew — the crew
 * was handed to an acting foreman, or the cover they were running ended
 * (Phase 7, TC-05). Keeping it would let them go on marking a crew they no
 * longer lead, only for every record to be refused on sync.
 *
 * Attendance already recorded is untouched: it is signed history, and taps
 * made while they still led the crew are still accepted when they sync.
 */
export async function clearRosterCache(): Promise<void> {
  const db = await getDatabase();

  await db.transaction(async tx => {
    await tx.execute('DELETE FROM crew_roster_cache;');
    await tx.execute("DELETE FROM app_settings WHERE key = 'roster_acting';");
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

  const actingRow = await db.execute(
    "SELECT value FROM app_settings WHERE key = 'roster_acting';",
  );
  const actingRaw = actingRow.rows.length === 0 ? '' : String((actingRow.rows[0] as any).value);
  let acting: ActingCover | null = null;
  try {
    acting = actingRaw ? JSON.parse(actingRaw) : null;
  } catch {
    acting = null;
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
    acting,
  };
}

/** A row of STATE_SQL: the latest roll call, with its newer time-out as out_*. */
function rowToRecord(row: any): TodayRecord {
  const timeOut = row.out_time_out ?? null;

  return {
    employeeId: row.employee_id,
    date: row.date,
    status: row.status as AttendanceStatus,
    timeIn: row.time_in,
    overrideType: row.override_type ?? null,
    capturedAt: row.captured_at ?? null,
    // A cleared time-out (Undo) is an event with no time: the day is open again.
    timeOut,
    timeOutType: timeOut === null ? null : row.out_time_out_type ?? null,
    timeOutCapturedAt: timeOut === null ? null : row.out_captured_at ?? null,
  };
}

/**
 * Current state per worker on a date: the latest roll-call event, and the
 * latest time-out event if it is newer than that roll call. A time-out older
 * than the latest roll call closed a status that has since been re-marked or
 * undone, so it no longer applies — the server drops it the same way.
 *
 * Events written before payload v3 are roll calls (schema v5 defaults them).
 */
const STATE_SQL = `
  SELECT r.*, t.time_out AS out_time_out, t.time_out_type AS out_time_out_type,
         t.captured_at AS out_captured_at
    FROM attendance_events r
    LEFT JOIN attendance_events t ON t.event_id = (
      SELECT MAX(event_id) FROM attendance_events
       WHERE employee_id = r.employee_id AND date = r.date
         AND event_type = 'time_out' AND event_id > r.event_id
    )
   WHERE r.event_id IN (
      SELECT MAX(event_id) FROM attendance_events
       WHERE date = ? AND event_type = 'roll_call' /*employee*/
       GROUP BY employee_id
   );`;

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

async function currentRecords(date: string, employeeId?: number): Promise<TodayRecord[]> {
  const db = await getDatabase();
  const result =
    employeeId === undefined
      ? await db.execute(STATE_SQL.replace('/*employee*/', ''), [date])
      : await db.execute(STATE_SQL.replace('/*employee*/', 'AND employee_id = ?'), [
          date,
          employeeId,
        ]);

  return (result.rows as any[]).map(rowToRecord);
}

/** One employee's current state on a date, or a blank record if none. */
async function currentRecord(employeeId: number, date: string): Promise<TodayRecord> {
  const [record] = await currentRecords(date, employeeId);

  return record ?? {...blankRecordFor(employeeId, date), ...NO_TIME_OUT, capturedAt: null};
}

export async function getTodayAttendance(employeeId: number): Promise<TodayRecord> {
  return currentRecord(employeeId, todayLocalDate());
}

/**
 * Current state for every employee marked today. Earlier events for the same
 * employee remain in the log; they are history, not current state.
 */
export async function listTodayAttendance(): Promise<Map<number, TodayRecord>> {
  const map = new Map<number, TodayRecord>();
  for (const record of await currentRecords(todayLocalDate())) {
    map.set(record.employeeId, record);
  }

  return map;
}

/** The signed fields that say what an event records; the rest is chain and clock. */
type EventFields = Pick<
  AttendancePayloadRecord,
  | 'employee_id'
  | 'date'
  | 'event_type'
  | 'status'
  | 'time_in'
  | 'override_type'
  | 'time_out'
  | 'time_out_type'
>;

/**
 * Append one chained, signed event.
 *
 * Order matters and is deliberate: capture the clock first (synchronously, at
 * the tap), then chain, then sign. Signing is the slow step and happens last,
 * over values already fixed — so its latency cannot influence what was
 * attested.
 *
 * `fieldsAt` receives the captured tap time, so an ordinary tap can be
 * recorded at exactly that instant. It may throw to refuse the event; nothing
 * has been written by then.
 */
async function appendEvent(
  crewId: number,
  fieldsAt: (tapMs: number) => EventFields,
): Promise<{fields: EventFields; capturedAt: number}> {
  const credentials = await loadCredentials();

  if (credentials === null) {
    // Refuse rather than writing an unsigned row. The server would reject it
    // anyway, so a fallback would only lose the foreman's work silently.
    throw new DeviceNotBoundError();
  }

  const clock = captureClock();
  const fields = fieldsAt(clock.wallClockMs);
  const epoch = chainEpoch(credentials);
  const prevHash = await currentChainTip(epoch);

  const payload: AttendancePayloadRecord = {
    ...fields,
    crew_id: crewId,
    captured_at: clock.wallClockMs,
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
       hmac_hash, ecdsa_signature, override_type, captured_at,
       payload_version, event_type, time_out, time_out_type, sync_status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending');`,
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
      payload.override_type,
      payload.captured_at,
      PAYLOAD_VERSION,
      payload.event_type,
      payload.time_out,
      payload.time_out_type,
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

  return {fields, capturedAt: payload.captured_at};
}

/**
 * Append a roll-call event. A roll call closes nothing, so it also clears any
 * time-out recorded against the earlier status — the server does the same.
 */
async function appendRollCall(record: AttendanceRecord, crewId: number): Promise<TodayRecord> {
  const {fields, capturedAt} = await appendEvent(crewId, tapMs => ({
    employee_id: record.employeeId,
    date: record.date,
    event_type: 'roll_call',
    status: record.status,
    /*
     * An ordinary arrival is credited at the captured tap itself, not at the
     * Date.now() the state machine read earlier — those are separate reads
     * with awaits in between, and the server refuses a time_in that drifts
     * from captured_at. Only an override may carry a different time, and it
     * says so in the signed override_type.
     */
    time_in: record.timeIn === null || record.overrideType !== null ? record.timeIn : tapMs,
    override_type: record.overrideType,
    time_out: null,
    time_out_type: null,
  }));

  // What was actually signed, so the UI shows the stored times, not the
  // state machine's earlier reading.
  return {...record, ...NO_TIME_OUT, timeIn: fields.time_in, capturedAt};
}

/**
 * Append a time-out event closing (or, with a null time, reopening) the
 * worker's day. It restates the status it closes — the server refuses one
 * that disagrees with the record — and nothing about arrival.
 */
async function appendTimeOut(
  current: TodayRecord,
  crewId: number,
  timeOutAt: (tapMs: number) => {timeOut: number | null; timeOutType: TimeOutType | null},
): Promise<TodayRecord> {
  const {fields, capturedAt} = await appendEvent(crewId, tapMs => {
    const {timeOut, timeOutType} = timeOutAt(tapMs);

    return {
      employee_id: current.employeeId,
      date: current.date,
      event_type: 'time_out',
      status: current.status,
      time_in: null,
      override_type: null,
      time_out: timeOut,
      time_out_type: timeOutType,
    };
  });

  return {
    ...current,
    timeOut: fields.time_out,
    timeOutType: fields.time_out_type,
    timeOutCapturedAt: fields.time_out === null ? null : capturedAt,
  };
}

/**
 * Foreman taps Present/Late/Absent. Appends a new signed event — no network
 * call in this path, which is what keeps roll call offline-capable.
 */
export async function recordStatus(
  employeeId: number,
  crewId: number,
  status: 'present' | 'late' | 'absent',
): Promise<TodayRecord> {
  const date = todayLocalDate();
  const current = await currentRecord(employeeId, date);

  return appendRollCall(selectStatus(current, status, Date.now()), crewId);
}

/**
 * Undo — appends a reverting event rather than deleting anything. The original
 * event stays in the log and in the chain, which is what makes the correction
 * itself auditable rather than invisible.
 *
 * Undo takes back the last thing done: a time-out first, reopening the day,
 * and only then the mark itself.
 */
export async function undoAttendance(
  employeeId: number,
  crewId: number,
): Promise<TodayRecord> {
  const date = todayLocalDate();
  const current = await currentRecord(employeeId, date);

  if (current.timeOut !== null) {
    return appendTimeOut(current, crewId, () => ({timeOut: null, timeOutType: null}));
  }

  return appendRollCall(undoStatus(current), crewId);
}

/* ------------------------------------------------------------------------ *
 * Time-out (payload v3)
 *
 * The device-side mirror of backend TimeOutPolicy: each writer refuses, before
 * signing, a time-out the server would refuse on sync.
 * ------------------------------------------------------------------------ */

async function onSiteRecord(employeeId: number, date: string): Promise<TodayRecord> {
  const current = await currentRecord(employeeId, date);

  if (!isOnSite(current)) {
    throw new TimeOutRefusedError(
      current.timeOut === null
        ? 'Only a worker marked Present or Late can be timed out.'
        : 'This worker is already timed out. Undo it first to change it.',
    );
  }

  return current;
}

/** "Out", tapped as the worker leaves: timed out at the tap itself. */
export async function recordTimeOut(employeeId: number, crewId: number): Promise<TodayRecord> {
  const current = await onSiteRecord(employeeId, todayLocalDate());

  return appendTimeOut(current, crewId, tapMs => ({timeOut: tapMs, timeOutType: null}));
}

/**
 * A time-out the foreman states ("forgot to tap out"). After the time in, and
 * not after the tap — a departure cannot be stated for a moment that has not
 * happened yet. Held for HR review as MANUAL_TIME_OUT.
 */
export async function recordManualTimeOut(
  employeeId: number,
  crewId: number,
  timeOutMs: number,
): Promise<TodayRecord> {
  const current = await onSiteRecord(employeeId, todayLocalDate());

  return appendTimeOut(current, crewId, tapMs => {
    if (current.timeIn !== null && timeOutMs <= current.timeIn) {
      throw new TimeOutRefusedError('A time-out has to be after the time in.');
    }

    if (timeOutMs > tapMs) {
      throw new TimeOutRefusedError('A time-out cannot be later than now.');
    }

    return {timeOut: timeOutMs, timeOutType: 'manual_time'};
  });
}

/**
 * Close shift: everyone still on site is timed out at exactly shift end. Not
 * reviewed — it asserts only that nobody left early, and a worker who did is
 * tapped Out first.
 *
 * Skips workers already out, absent or unmarked. Each worker is a separate
 * signed event; if one fails the ones before it stand, so the screen reloads
 * rather than trusting the returned list.
 */
export async function closeShift(crewId: number, employeeIds: number[]): Promise<TodayRecord[]> {
  const date = todayLocalDate();
  const shift = await getShiftConfig();
  const end = shiftEndMs(date, shift);
  const closed: TodayRecord[] = [];

  for (const employeeId of employeeIds) {
    const current = await currentRecord(employeeId, date);

    if (!isOnSite(current)) {
      continue;
    }

    closed.push(
      await appendTimeOut(current, crewId, tapMs => {
        if (!canCloseShift(tapMs, date, shift)) {
          throw new TimeOutRefusedError(
            `Close shift opens at ${formatSiteTime(end, shift)}. Tap Out for anyone leaving earlier.`,
          );
        }

        return {timeOut: end, timeOutType: 'shift_end'};
      }),
    );
  }

  return closed;
}

/* ------------------------------------------------------------------------ *
 * Late start (Phase 7 — UC-05, STD TC-04)
 * ------------------------------------------------------------------------ */

async function readSetting(key: string): Promise<string | null> {
  const db = await getDatabase();
  const result = await db.execute('SELECT value FROM app_settings WHERE key = ?;', [key]);

  return result.rows.length === 0 ? null : String((result.rows[0] as any).value);
}

async function writeSetting(key: string, value: string): Promise<void> {
  const db = await getDatabase();
  await db.execute(
    'INSERT INTO app_settings (key, value) VALUES (?, ?) ' +
      'ON CONFLICT(key) DO UPDATE SET value = excluded.value;',
    [key, value],
  );
}

/** Cached from GET /api/me/crew, so the late-start and Close-shift rules work offline. */
export async function saveShiftConfig(shift: ShiftConfig): Promise<void> {
  await writeSetting('shift_config', JSON.stringify(shift));
}

const isClockTime = (value: unknown): value is string =>
  typeof value === 'string' && /^\d{2}:\d{2}$/.test(value);

/**
 * The server's shift rules as last fetched, or its defaults before the first
 * fetch. A malformed cache falls back rather than throwing: roll call must
 * still open, and the server re-checks every override on sync regardless.
 *
 * A cache saved before the server sent a shift end lacks one until the next
 * roster fetch, and takes the default end meanwhile.
 */
export async function getShiftConfig(): Promise<ShiftConfig> {
  const raw = await readSetting('shift_config');

  if (raw === null) {
    return DEFAULT_SHIFT;
  }

  try {
    const parsed = JSON.parse(raw);

    return isClockTime(parsed.start) &&
      Number.isInteger(parsed.late_override_grace_minutes) &&
      Number.isInteger(parsed.utc_offset_minutes)
      ? {...parsed, end: isClockTime(parsed.end) ? parsed.end : DEFAULT_SHIFT.end}
      : DEFAULT_SHIFT;
  } catch {
    return DEFAULT_SHIFT;
  }
}

/**
 * How the foreman chose to record a late start:
 *   credit — everyone marked Present is credited from shift start
 *   manual — the foreman states each arrival time
 *   none   — ordinary taps; records carry the real tap time
 */
export type LateStartMode = 'credit' | 'manual' | 'none';

export type LateStartChoice = {mode: LateStartMode; decidedAt: number};

const lateStartKey = (crewId: number, date: string) => `late_start:${crewId}:${date}`;

/** The choice already made for this crew today, so it is asked once, not on every visit. */
export async function getLateStartChoice(
  crewId: number,
  date: string = todayLocalDate(),
): Promise<LateStartChoice | null> {
  const raw = await readSetting(lateStartKey(crewId, date));

  if (raw === null) {
    return null;
  }

  try {
    const parsed = JSON.parse(raw);
    return ['credit', 'manual', 'none'].includes(parsed.mode) ? parsed : null;
  } catch {
    return null;
  }
}

/**
 * Remember the choice for today. Other days' choices are cleared at the same
 * time: they are never read again, since the key carries the date. Other crews'
 * choices for today are kept — an acting foreman can run two roll calls.
 *
 * Only a UI preference. Nothing about the override lives here — each record
 * carries its own signed override_type, and the server raises the audit event
 * from those.
 */
export async function saveLateStartChoice(
  crewId: number,
  mode: LateStartMode,
  date: string = todayLocalDate(),
): Promise<LateStartChoice> {
  const choice: LateStartChoice = {mode, decidedAt: Date.now()};
  const db = await getDatabase();

  await db.execute(
    "DELETE FROM app_settings WHERE key LIKE 'late_start:%' AND key NOT LIKE ?;",
    [`late_start:%:${date}`],
  );
  await writeSetting(lateStartKey(crewId, date), JSON.stringify(choice));

  return choice;
}

/**
 * Whether anyone on this crew has been marked today. A roll call already under
 * way is not a late start, even if the foreman comes back to it hours later.
 */
export async function hasRollCallStarted(
  crewId: number,
  date: string = todayLocalDate(),
): Promise<boolean> {
  const db = await getDatabase();
  const result = await db.execute(
    'SELECT 1 FROM attendance_events WHERE crew_id = ? AND date = ? LIMIT 1;',
    [crewId, date],
  );

  return result.rows.length > 0;
}

/** Present, credited from shift start — the worker was on site before the foreman. */
export async function recordShiftCredit(
  employeeId: number,
  crewId: number,
): Promise<TodayRecord> {
  const date = todayLocalDate();
  const [current, shift] = await Promise.all([currentRecord(employeeId, date), getShiftConfig()]);

  return appendRollCall(creditAtShiftStart(current, date, shift), crewId);
}

/** Present or Late at an arrival time the foreman states ("Set each time myself"). */
export async function recordManualTime(
  employeeId: number,
  crewId: number,
  status: 'present' | 'late',
  timeInMs: number,
): Promise<TodayRecord> {
  const current = await currentRecord(employeeId, todayLocalDate());

  return appendRollCall(withManualTime(current, status, timeInMs), crewId);
}

/* ------------------------------------------------------------------------ *
 * Sync (Phase 6)
 * ------------------------------------------------------------------------ */

/**
 * sync_status values on attendance_events:
 *   pending  — captured, not yet confirmed by the server
 *   synced   — accepted and trusted
 *   flagged  — accepted but time-suspect, sent for HR review
 *   refused  — authentic, but not permitted (e.g. the crew has another
 *              foreman now). Not saved server-side, but the chain is intact,
 *              so later events still sync. Phase 7.
 *   rejected — failed verification or orphaned by an earlier rejection. Breaks
 *              the chain.
 *
 * "Kept until confirmed by server" (the Sync Queue prototype): nothing is
 * deleted on sync. Rows only change status.
 */
export type EventSyncStatus = 'pending' | 'synced' | 'flagged' | 'refused' | 'rejected';

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
  outcomes: {hmacHash: string; status: 'accepted' | 'flagged' | 'refused' | 'rejected'}[],
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

/**
 * Unsent events in a binding's chain. Before a phone is set up for a different
 * foreman, so they can be warned that the records still waiting belong to the
 * previous foreman and only that foreman can send them.
 */
export async function countPendingEvents(epoch: string): Promise<number> {
  const db = await getDatabase();
  const result = await db.execute(
    "SELECT COUNT(*) AS n FROM attendance_events WHERE sync_status = 'pending' AND chain_epoch = ?;",
    [epoch],
  );

  return result.rows.length === 0 ? 0 : Number((result.rows[0] as any).n);
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
  /** The record's standing: the worse of its roll call and its time-out. */
  syncStatus: EventSyncStatus;
  overrideType: OverrideType | null;
  timeOut: number | null;
  timeOutType: TimeOutType | null;
};

/**
 * Which of two events' outcomes a record shows: a failure before anything
 * still waiting, and anything waiting before a review — so a time-out sent
 * cleanly never hides a roll call that was not.
 */
const SYNC_STATUS_WEIGHT: Record<EventSyncStatus, number> = {
  rejected: 4,
  refused: 3,
  pending: 2,
  flagged: 1,
  synced: 0,
};

function worseOf(a: EventSyncStatus, b: EventSyncStatus | null): EventSyncStatus {
  return b !== null && SYNC_STATUS_WEIGHT[b] > SYNC_STATUS_WEIGHT[a] ? b : a;
}

const failed = (status: EventSyncStatus) => status === 'rejected' || status === 'refused';

export type SyncSummary = {
  pending: number;
  synced: number;
  flagged: number;
  refused: number;
  rejected: number;
  lastSuccessfulSync: number | null;
  rows: SyncQueueRow[];
};

/**
 * Everything the Sync Queue screen shows.
 *
 * One row per employee+day, matching what the foreman thinks of as "a record"
 * — an Undo followed by a re-tap is one person, not three rows. A record is
 * its latest roll call and any newer time-out, as on Roll Call; the time-out
 * is the latest event, but the arrival it closes still has to reach the
 * server. Failed sorts first ("Failed first", per the prototype), then oldest
 * first within each group ("As marked, oldest first").
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
            e.override_type, t.time_out, t.time_out_type, t.sync_status AS out_sync_status,
            r.first_name, r.last_name
       FROM attendance_events e
       JOIN (
         SELECT employee_id, date, MAX(event_id) AS latest_id
           FROM attendance_events WHERE chain_epoch = ? AND event_type = 'roll_call'
          GROUP BY employee_id, date
       ) newest ON newest.latest_id = e.event_id
       LEFT JOIN attendance_events t ON t.event_id = (
         SELECT MAX(event_id) FROM attendance_events
          WHERE chain_epoch = e.chain_epoch AND employee_id = e.employee_id
            AND date = e.date AND event_type = 'time_out' AND event_id > e.event_id
       )
       LEFT JOIN crew_roster_cache r ON r.employee_id = e.employee_id
      ORDER BY e.event_id;`,
    [epoch],
  );

  const last = await db.execute(
    "SELECT value FROM app_settings WHERE key = 'last_successful_sync';",
  );

  return {
    pending: tally.pending ?? 0,
    synced: tally.synced ?? 0,
    flagged: tally.flagged ?? 0,
    refused: tally.refused ?? 0,
    rejected: tally.rejected ?? 0,
    lastSuccessfulSync:
      last.rows.length === 0 ? null : Number((last.rows[0] as any).value),
    rows: (rows.rows as any[])
      .map(row => ({
        eventId: row.event_id,
        employeeName:
          row.last_name && row.first_name
            ? `${row.last_name}, ${row.first_name}`
            : `Employee #${row.event_id}`,
        status: row.status,
        timeIn: row.time_in,
        capturedAt: row.captured_at,
        syncStatus: worseOf(row.sync_status, row.out_sync_status ?? null),
        overrideType: row.override_type ?? null,
        timeOut: row.time_out ?? null,
        timeOutType: row.time_out == null ? null : row.time_out_type ?? null,
      }))
      // Stable, so each group keeps the query's oldest-first order.
      .sort((a, b) => Number(failed(b.syncStatus)) - Number(failed(a.syncStatus))),
  };
}
