/**
 * OTA rollback guard (Add-on C, O1): a bundle older than the database must
 * never lower the stored schema marker. Lowering it makes the next upgrade
 * re-run an already-applied migration, hit "duplicate column", and stop
 * opening the app — with the unsynced attendance still on the phone but
 * unreachable.
 *
 * Drives initSchema through getDatabase() against a fake schema_version
 * marker behind op-sqlite's jest mock, mirroring requestDrafts.test.ts.
 *
 * @format
 */

import {open} from '@op-engineering/op-sqlite';
import {getDatabase, resetDatabaseInstanceForTests, SCHEMA_VERSION} from '../database';

type Call = {sql: string; params: any[]};

/** Fake DB whose schema_version marker starts at `stored`, or absent. */
async function withMarker(stored: number | null): Promise<{calls: Call[]}> {
  const calls: Call[] = [];
  const execute = jest.fn(async (sql: string, params: any[] = []) => {
    calls.push({sql, params});
    if (sql.startsWith('SELECT value FROM app_settings')) {
      return {
        rows: stored === null ? [] : [{value: String(stored)}],
        rowsAffected: 0,
      };
    }
    return {rows: [], rowsAffected: 0};
  });
  // Install before getDatabase() — initSchema runs inside open(), and the
  // requestDrafts-style override-after-open would miss it entirely.
  (open as jest.Mock).mockImplementationOnce(() => ({execute}));
  await getDatabase();
  return {calls};
}

// setSchemaVersion inlines the key and binds only the value, so the check
// is on params[0] of schema_version upserts.
function schemaVersionWrites(calls: Call[]): string[] {
  return calls
    .filter(
      call =>
        call.sql.startsWith('INSERT INTO app_settings') &&
        call.sql.includes('schema_version'),
    )
    .map(call => String(call.params[0]));
}

function migrationDdl(calls: Call[]): string[] {
  return calls
    .map(call => call.sql)
    .filter(
      sql =>
        sql.startsWith('ALTER TABLE') ||
        sql.startsWith('DROP TABLE') ||
        sql.startsWith('CREATE INDEX'),
    );
}

beforeEach(() => {
  jest.clearAllMocks();
  resetDatabaseInstanceForTests();
});

test('a rollback to an older bundle leaves the newer marker untouched', async () => {
  const {calls} = await withMarker(SCHEMA_VERSION + 2);

  expect(schemaVersionWrites(calls)).toEqual([]);
  expect(migrationDdl(calls)).toEqual([]);
});

test('a fresh install still initializes the marker to the running version', async () => {
  const {calls} = await withMarker(null);

  expect(schemaVersionWrites(calls)).toContain(String(SCHEMA_VERSION));
});

test('an upgrade from an older marker still advances it', async () => {
  const {calls} = await withMarker(SCHEMA_VERSION - 2);

  expect(schemaVersionWrites(calls)).toContain(String(SCHEMA_VERSION));
});
