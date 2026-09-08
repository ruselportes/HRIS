/**
 * Local SQLite schema (UC-04), via @op-engineering/op-sqlite (JSI-based,
 * New Architecture compatible — swapped in from react-native-sqlite-storage
 * after that package's Android build proved incompatible with this
 * project's toolchain: an embedded, unmaintained AGP 3.1.4/jcenter()
 * buildscript block, and no support for newArchEnabled=true at all).
 *
 * NOT part of the 13-table ERD — this is a device-local cache/capture
 * layer, documented here rather than silently extending the central schema
 * (same pattern as Phase 3's crews.status/deployed_at extension).
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
 * Phase 5 hook point: op-sqlite supports SQLCipher via the
 * `OP_SQLITE_USE_SQLCIPHER` native build flag (package.json config) plus an
 * `encryptionKey` passed to open() — neither is enabled here, per the
 * Phase 1 decision to defer SQLCipher until the crypto engine phase.
 *
 * @format
 */

import {open, type DB} from '@op-engineering/op-sqlite';

const DB_NAME = 'hris_offline.db';

let dbInstance: DB | null = null;

export async function getDatabase(): Promise<DB> {
  if (dbInstance) {
    return dbInstance;
  }

  dbInstance = open({name: DB_NAME});
  await initSchema(dbInstance);

  return dbInstance;
}

async function initSchema(db: DB): Promise<void> {
  await db.execute(`
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

  await db.execute(`
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

  await db.execute(`
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

  await db.execute(`
    CREATE TABLE IF NOT EXISTS app_settings (
      key TEXT PRIMARY KEY NOT NULL,
      value TEXT NOT NULL
    );
  `);
}

/** Test/dev-only escape hatch to force a fresh open() next call. */
export function resetDatabaseInstanceForTests(): void {
  dbInstance = null;
}
