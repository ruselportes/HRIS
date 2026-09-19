/**
 * Saved request drafts (Phase 9 — UC-10): a request the foreman composed but
 * has not sent, kept in app_settings so it survives closing the app. It is
 * only a draft — HR cannot see it until it is sent.
 *
 * One draft per foreman, keyed by who saved it. A phone can change hands (an
 * acting foreman, TC-05): nobody may send another person's draft under their
 * own name, nor overwrite it by saving their own.
 *
 * @format
 */

import {getDatabase} from './database';
import type {RequestDraft} from '../requests/requestDraft';

const keyFor = (ownerId: number) => `request_draft:${ownerId}`;

export type SavedDraft = {savedAt: number; draft: RequestDraft};

export async function saveDraft(ownerId: number, draft: RequestDraft, savedAt: number = Date.now()): Promise<void> {
  const db = await getDatabase();
  await db.execute(
    'INSERT INTO app_settings (key, value) VALUES (?, ?) ' +
      'ON CONFLICT(key) DO UPDATE SET value = excluded.value;',
    [keyFor(ownerId), JSON.stringify({savedAt, draft})],
  );
}

/** This foreman's saved draft, or null when there is none or it cannot be read. */
export async function loadDraft(ownerId: number): Promise<SavedDraft | null> {
  const db = await getDatabase();
  const result = await db.execute('SELECT value FROM app_settings WHERE key = ?;', [keyFor(ownerId)]);

  if (result.rows.length === 0) {
    return null;
  }

  try {
    const saved = JSON.parse(String((result.rows[0] as any).value));
    const kind = saved?.draft?.kind;

    return kind === 'overtime' || kind === 'leave' ? saved : null;
  } catch {
    return null;
  }
}

export async function clearDraft(ownerId: number): Promise<void> {
  const db = await getDatabase();
  await db.execute('DELETE FROM app_settings WHERE key = ?;', [keyFor(ownerId)]);
}
