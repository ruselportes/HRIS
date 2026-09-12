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

/**
 * Bumped whenever the local schema changes. v2 (Phase 5) replaced the
 * in-place `attendance` table with the append-only `attendance_events` log —
 * see the migration below for why that was forced rather than chosen.
 */
const SCHEMA_VERSION = 2;

async function currentSchemaVersion(db: DB): Promise<number> {
  await db.execute(`
    CREATE TABLE IF NOT EXISTS app_settings (
      key TEXT PRIMARY KEY NOT NULL,
      value TEXT NOT NULL
    );
  `);

  const result = await db.execute(
    "SELECT value FROM app_settings WHERE key = 'schema_version';",
  );

  if (result.rows.length === 0) {
    // No marker: either a fresh install, or a v1 install predating versioning.
    // Distinguished by whether the v1 table exists.
    const legacy = await db.execute(
      "SELECT name FROM sqlite_master WHERE type='table' AND name='attendance';",
    );

    return legacy.rows.length > 0 ? 1 : 0;
  }

  return Number((result.rows[0] as any).value);
}

async function setSchemaVersion(db: DB, version: number): Promise<void> {
  await db.execute(
    "INSERT INTO app_settings (key, value) VALUES ('schema_version', ?) " +
      'ON CONFLICT(key) DO UPDATE SET value = excluded.value;',
    [String(version)],
  );
}

async function initSchema(db: DB): Promise<void> {
  const from = await currentSchemaVersion(db);

  /*
   * v1 -> v2: the `attendance` table was updated in place, keyed
   * UNIQUE(employee_id, date). That is incompatible with hash chaining: once a
   * row is chained, editing it invalidates its own HMAC and orphans every row
   * after it. Re-tapping a worker (Present -> Late, or Undo) is ordinary
   * foreman behaviour, so in-place updates would break the chain constantly.
   *
   * v1 rows carried no chain or signature data, so there is nothing to
   * preserve — they cannot be retrofitted into a chain they were never part
   * of. Dropped rather than migrated, which is safe only because this has
   * never shipped past the emulator. It would not be safe after release.
   */
  if (from === 1) {
    await db.execute('DROP TABLE IF EXISTS attendance;');
    await db.execute('DROP TABLE IF EXISTS attendance_sync_queue;');
  }

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

  /*
   * Append-only signed event log. Every tap writes a new row; nothing is ever
   * updated or deleted, because a hash chain cannot tolerate rewriting
   * history — that is the property TC-02 relies on.
   *
   * Current UI state is therefore derived (latest event per employee+date)
   * rather than stored, so there is one source of truth instead of two tables
   * that can silently diverge.
   *
   * The columns mirror the canonical payload exactly, because this row IS what
   * was signed. prev_hash/hmac_hash/ecdsa_signature are NOT NULL: a row
   * without them could never be verified, so there is no legitimate way to
   * produce one.
   */
  await db.execute(`
    CREATE TABLE IF NOT EXISTS attendance_events (
      event_id INTEGER PRIMARY KEY AUTOINCREMENT,
      employee_id INTEGER NOT NULL,
      crew_id INTEGER NOT NULL,
      date TEXT NOT NULL,
      status TEXT NOT NULL,
      time_in INTEGER,
      monotonic_timestamp INTEGER NOT NULL,
      boot_id TEXT NOT NULL,
      boot_id_system_backed INTEGER NOT NULL DEFAULT 0,
      device_id TEXT NOT NULL,
      prev_hash TEXT,
      hmac_hash TEXT NOT NULL,
      ecdsa_signature TEXT NOT NULL,
      override_flag INTEGER NOT NULL DEFAULT 0,
      captured_at INTEGER NOT NULL,
      sync_status TEXT NOT NULL DEFAULT 'pending'
    );
  `);

  // Deriving current state reads the newest event per employee for a date.
  await db.execute(`
    CREATE INDEX IF NOT EXISTS idx_attendance_events_lookup
      ON attendance_events (date, employee_id, event_id);
  `);

  // Draining the queue reads pending events in chain order.
  await db.execute(`
    CREATE INDEX IF NOT EXISTS idx_attendance_events_sync
      ON attendance_events (sync_status, event_id);
  `);

  /*
   * Mirrors ERD tbl_attendance_sync_queue. attendance_events carries its own
   * sync_status, so this table exists for per-attempt history — retry counts
   * and timings — which is what Phase 6's engine needs and what the ERD
   * models. Repointed from the dropped v1 `attendance` table to event_id.
   */
  await db.execute(`
    CREATE TABLE IF NOT EXISTS attendance_sync_queue (
      queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
      event_id INTEGER NOT NULL,
      device_id TEXT NOT NULL,
      queued_at INTEGER NOT NULL,
      synced_at INTEGER,
      sync_status TEXT NOT NULL DEFAULT 'pending',
      FOREIGN KEY (event_id) REFERENCES attendance_events(event_id)
    );
  `);

  // app_settings is created by currentSchemaVersion() above, since the version
  // marker lives in it and has to be readable before any migration runs.

  await setSchemaVersion(db, SCHEMA_VERSION);
}

/** Test/dev-only escape hatch to force a fresh open() next call. */
export function resetDatabaseInstanceForTests(): void {
  dbInstance = null;
}
