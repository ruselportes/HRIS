/**
 * Saved request drafts: one per foreman, never readable by another, and a
 * corrupt entry reads as no draft rather than crashing the form.
 *
 * @format
 */

import {getDatabase, resetDatabaseInstanceForTests} from '../database';
import {clearDraft, loadDraft, saveDraft} from '../requestDrafts';
import type {OvertimeDraft} from '../../requests/requestDraft';

const DRAFT: OvertimeDraft = {
  kind: 'overtime',
  workerIds: [11, 12],
  date: '2026-09-22',
  start: '16:00',
  hours: 3,
  reason: '',
  batchKey: null,
};

/** A fake app_settings table behind the op-sqlite mock. */
async function withStore(): Promise<Map<string, string>> {
  const rows = new Map<string, string>();
  const db = await getDatabase();

  (db.execute as jest.Mock).mockImplementation(async (sql: string, params: any[] = []) => {
    if (sql.startsWith('INSERT INTO app_settings')) {
      rows.set(params[0], params[1]);
    } else if (sql.startsWith('DELETE FROM app_settings')) {
      rows.delete(params[0]);
    } else if (sql.startsWith('SELECT value FROM app_settings')) {
      return {rows: rows.has(params[0]) ? [{value: rows.get(params[0])}] : [], rowsAffected: 0};
    }
    return {rows: [], rowsAffected: 0};
  });

  return rows;
}

beforeEach(() => {
  jest.clearAllMocks();
  resetDatabaseInstanceForTests();
});

test('a draft is kept per foreman: another foreman neither sees nor overwrites it', async () => {
  await withStore();

  await saveDraft(900, DRAFT, 1000);
  await saveDraft(901, {...DRAFT, workerIds: [13]}, 2000);

  expect(await loadDraft(900)).toEqual({savedAt: 1000, draft: DRAFT});
  expect((await loadDraft(901))?.draft).toMatchObject({workerIds: [13]});
  expect(await loadDraft(902)).toBeNull();
});

test('clearing removes only that foreman\'s draft', async () => {
  await withStore();
  await saveDraft(900, DRAFT);
  await saveDraft(901, DRAFT);

  await clearDraft(900);

  expect(await loadDraft(900)).toBeNull();
  expect(await loadDraft(901)).not.toBeNull();
});

test('an unreadable entry reads as no draft', async () => {
  const rows = await withStore();

  rows.set('request_draft:900', 'not json');
  expect(await loadDraft(900)).toBeNull();

  rows.set('request_draft:900', JSON.stringify({savedAt: 1, draft: {kind: 'holiday'}}));
  expect(await loadDraft(900)).toBeNull();
});
