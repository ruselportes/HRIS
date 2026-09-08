/**
 * SQLite-backed repository. Thin wrapper around database.ts + the pure
 * state machine in attendanceLogic.ts — no business logic lives here beyond
 * translating rows <-> the AttendanceRecord shape.
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

/** Replaces the whole cached roster with a fresh fetch from GET /api/me/crew. */
export async function saveRosterCache(
  crewId: number,
  crewName: string,
  siteName: string | null,
  members: RosterMember[],
): Promise<void> {
  const db = await getDatabase();
  const cachedAt = Date.now();

  await db.transaction(async (tx: any) => {
    await tx.executeSql('DELETE FROM crew_roster_cache;');

    for (const member of members) {
      await tx.executeSql(
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
  const [result] = await db.executeSql('SELECT * FROM crew_roster_cache ORDER BY last_name;');

  if (result.rows.length === 0) {
    return null;
  }

  const members: RosterMember[] = [];
  let crewId = 0;
  let crewName = '';
  let siteName: string | null = null;
  let cachedAt = 0;

  for (let i = 0; i < result.rows.length; i++) {
    const row = result.rows.item(i);
    crewId = row.crew_id;
    crewName = row.crew_name;
    siteName = row.site_name;
    cachedAt = row.cached_at;
    members.push({
      employeeId: row.employee_id,
      employeeCode: row.employee_code,
      firstName: row.first_name,
      lastName: row.last_name,
      tradeSkill: row.trade_skill,
    });
  }

  return {crewId, crewName, siteName, cachedAt, members};
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

async function loadRecord(employeeId: number, date: string): Promise<AttendanceRecord> {
  const db = await getDatabase();
  const [result] = await db.executeSql(
    'SELECT * FROM attendance WHERE employee_id = ? AND date = ?;',
    [employeeId, date],
  );

  if (result.rows.length === 0) {
    return blankRecordFor(employeeId, date);
  }

  return rowToRecord(result.rows.item(0));
}

export async function getTodayAttendance(employeeId: number): Promise<AttendanceRecord> {
  return loadRecord(employeeId, todayLocalDate());
}

export async function listTodayAttendance(): Promise<Map<number, AttendanceRecord>> {
  const db = await getDatabase();
  const date = todayLocalDate();
  const [result] = await db.executeSql('SELECT * FROM attendance WHERE date = ?;', [date]);

  const map = new Map<number, AttendanceRecord>();
  for (let i = 0; i < result.rows.length; i++) {
    const row = result.rows.item(i);
    map.set(row.employee_id, rowToRecord(row));
  }

  return map;
}

async function persist(
  db: Awaited<ReturnType<typeof getDatabase>>,
  record: AttendanceRecord,
  crewId: number,
): Promise<void> {
  await db.executeSql(
    `INSERT INTO attendance (employee_id, crew_id, date, status, time_in, monotonic_timestamp, override_flag, sync_status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
     ON CONFLICT(employee_id, date) DO UPDATE SET
       status = excluded.status,
       time_in = excluded.time_in,
       monotonic_timestamp = excluded.monotonic_timestamp,
       override_flag = excluded.override_flag,
       sync_status = 'pending';`,
    [
      record.employeeId,
      crewId,
      record.date,
      record.status,
      record.timeIn,
      // PLACEHOLDER: wall-clock, not a real monotonic capture. Phase 5 owns
      // elapsedRealtime/mach_continuous_time + HMAC chaining + TEE signing.
      record.timeIn === null ? null : Date.now(),
      record.overrideFlag ? 1 : 0,
    ],
  );
}

async function enqueueSync(
  db: Awaited<ReturnType<typeof getDatabase>>,
  employeeId: number,
  date: string,
  deviceId: string,
): Promise<void> {
  const [row] = await db.executeSql(
    'SELECT local_id FROM attendance WHERE employee_id = ? AND date = ?;',
    [employeeId, date],
  );
  const localId = row.rows.item(0).local_id;

  await db.executeSql(
    `INSERT INTO attendance_sync_queue (local_attendance_id, device_id, queued_at, sync_status)
     VALUES (?, ?, ?, 'pending');`,
    [localId, deviceId, Date.now()],
  );
}

/**
 * Foreman taps Present/Late/Absent for a roster row. Entirely local — no
 * network call in this path, which is what makes roll call offline-capable.
 * Every (re-)selection re-queues a pending sync row; Phase 6's engine is
 * expected to de-dupe/collapse by local_attendance_id when it drains this.
 */
export async function recordStatus(
  employeeId: number,
  crewId: number,
  status: 'present' | 'late' | 'absent',
  deviceId: string,
): Promise<AttendanceRecord> {
  const date = todayLocalDate();
  const current = await loadRecord(employeeId, date);
  const updated = selectStatus(current, status, Date.now());

  const db = await getDatabase();
  await persist(db, updated, crewId);
  await enqueueSync(db, employeeId, date, deviceId);

  return updated;
}

/** Undo — reverts a marked row back to Pending, per the prototype's Undo action. */
export async function undoAttendance(
  employeeId: number,
  crewId: number,
  deviceId: string,
): Promise<AttendanceRecord> {
  const date = todayLocalDate();
  const current = await loadRecord(employeeId, date);
  const reverted = undoStatus(current);

  const db = await getDatabase();
  await persist(db, reverted, crewId);
  await enqueueSync(db, employeeId, date, deviceId);

  return reverted;
}
