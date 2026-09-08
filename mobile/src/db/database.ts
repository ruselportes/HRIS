/**
 * Local SQLite schema (UC-04). Deliberately NOT part of the 13-table ERD —
 * this is a device-local cache/capture layer, documented here rather than
 * silently extending the central schema (same pattern as Phase 3's
 * crews.status/deployed_at extension).
 *
 * Tables:
 *  - crew_roster_cache: last-fetched roster for the signed-in foreman's
 *    deployed crew (GET /api/me/crew), so the checklist works with zero
 *    connectivity after the first successful sync.
 *  - attendance: local capture per employee per day. Mirrors ERD
 *    tbl_attendance's shape (time_in, time_out, monotonic_timestamp,
 *    sync_status, override_flag) but monotonic_timestamp is a wall-clock
 *    (Date.now()) PLACEHOLDER — Phase 5 replaces this with real
 *    elapsedRealtime/mach_continuous_time capture + HMAC chain + signing.
 *    No crypto happens here; that is entirely out of Phase 4's scope.
 *  - attendance_sync_queue: mirrors ERD tbl_attendance_sync_queue. A queue
 *    row is created for every local attendance write; Phase 6 builds the
 *    engine that actually drains this queue to the server.
 *
 * @format
 */

import SQLite from 'react-native-sqlite-storage';

// react-native-sqlite-storage ships no types (declared as an untyped ambient
// module in src/types/) — SQLiteDb is `any` on purpose, not a shortcut.
type SQLiteDb = any;

SQLite.enablePromise(true);

const DB_NAME = 'hris_offline.db';

let dbInstance: SQLiteDb | null = null;

export async function getDatabase(): Promise<SQLiteDb> {
  if (dbInstance) {
    return dbInstance;
  }

  dbInstance = await SQLite.openDatabase({name: DB_NAME, location: 'default'});
  await initSchema(dbInstance);

  return dbInstance;
}

async function initSchema(db: SQLiteDb): Promise<void> {
  await db.executeSql(`
    CREATE TABLE IF NOT EXISTS crew_roster_cache (
      employee_id INTEGER PRIMARY KEY NOT NULL,
      employee_code TEXT,
      first_name TEXT NOT NULL,
      last_name TEXT NOT NULL,
      trade_skill TEXT,
      crew_id INTEGER NOT NULL,
      crew_name TEXT NOT NULL,
      site_name TEXT,
      cached_at INTEGER NOT NULL
    );
  `);

  await db.executeSql(`
    CREATE TABLE IF NOT EXISTS attendance (
      local_id INTEGER PRIMARY KEY AUTOINCREMENT,
      employee_id INTEGER NOT NULL,
      crew_id INTEGER NOT NULL,
      date TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending',
      time_in INTEGER,
      time_out INTEGER,
      monotonic_timestamp INTEGER,
      sync_status TEXT NOT NULL DEFAULT 'pending',
      override_flag INTEGER NOT NULL DEFAULT 0,
      UNIQUE(employee_id, date)
    );
  `);

  await db.executeSql(`
    CREATE TABLE IF NOT EXISTS attendance_sync_queue (
      queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
      local_attendance_id INTEGER NOT NULL,
      device_id TEXT NOT NULL,
      queued_at INTEGER NOT NULL,
      synced_at INTEGER,
      sync_status TEXT NOT NULL DEFAULT 'pending',
      FOREIGN KEY (local_attendance_id) REFERENCES attendance(local_id)
    );
  `);

  await db.executeSql(`
    CREATE TABLE IF NOT EXISTS app_settings (
      key TEXT PRIMARY KEY NOT NULL,
      value TEXT NOT NULL
    );
  `);
}

/** Test/dev-only escape hatch to force a fresh openDatabase() next call. */
export function resetDatabaseInstanceForTests(): void {
  dbInstance = null;
}
