/**
 * Background Sync Engine (Phase 6, supports UC-04).
 *
 * Drains the local signed event log into POST /api/attendance/sync.
 *
 * `runSync()` performs exactly ONE attempt and returns what happened. It owns
 * no timers and holds no schedule, so it is testable without faking time.
 * Retrying, backoff and connectivity triggers live in syncScheduler.ts.
 *
 * CHAIN-BREAK POLICY — the decision most worth understanding in this file.
 *
 * When the server rejects an event, every later event in that chain links onto
 * a hash the server refused and can never verify. The tempting recovery is to
 * re-chain those orphans onto the server's tip and re-sign them. That would be
 * unsafe: the HMAC secret sits in unencrypted local storage (see
 * deviceCredentials.ts), so an attacker with root can edit the orphaned records
 * too, and re-signing would give their edits a valid TEE signature — laundering
 * exactly the tampering TC-02 exists to catch.
 *
 * So a broken chain halts. The records are not deleted; they stay on the phone
 * for HR review and Phase 7's retroactive recovery workflow, and a rebind starts
 * a fresh chain for new work. Refusing to auto-heal is the security property,
 * not a missing feature.
 *
 * @format
 */

import {apiClient} from '../api/client';
import {chainEpoch, loadCredentials} from '../crypto/deviceCredentials';
import {
  applyEventOutcomes,
  getSyncSummary,
  listPendingEvents,
  markSyncedThrough,
  recordLastSuccessfulSync,
  recordSyncAttempt,
} from '../db/attendanceRepository';
import {HaltReason, classifyError, classifySuccess} from './classifyResponse';

/**
 * Events per request. Well under the server's 500 cap: a smaller batch keeps
 * each request short on a weak site connection, and a dropped request loses
 * less progress.
 */
export const BATCH_SIZE = 100;

export type SyncRunResult =
  | {kind: 'idle'}
  | {
      kind: 'synced';
      sent: number;
      accepted: number;
      flagged: number;
      refused: number;
      reconciled: number;
    }
  | {kind: 'retry'; reason: string; sent: number}
  | {kind: 'halted'; reason: HaltReason | 'unbound'};

/** Map a stored row to the exact wire shape the server validates. */
function toWireEvent(row: any) {
  return {
    employee_id: row.employee_id,
    crew_id: row.crew_id,
    date: row.date,
    status: row.status,
    // Sent explicitly even when null: the server requires the key to be
    // PRESENT, because a dropped key would change the canonical payload.
    time_in: row.time_in ?? null,
    // Both signed as of payload v2, so both are sent exactly as stored.
    captured_at: row.captured_at,
    override_type: row.override_type ?? null,
    monotonic_timestamp: row.monotonic_timestamp,
    boot_id: row.boot_id,
    device_id: row.device_id,
    prev_hash: row.prev_hash ?? null,
    hmac_hash: row.hmac_hash,
    ecdsa_signature: row.ecdsa_signature,
  };
}

export async function runSync(): Promise<SyncRunResult> {
  const credentials = await loadCredentials();

  if (credentials === null) {
    return {kind: 'halted', reason: 'unbound'};
  }

  const epoch = chainEpoch(credentials);

  // A chain the server has already broken cannot be repaired by sending more
  // of it. Checked before any network use, so a halted device does not keep
  // spending signal on requests that will all fail.
  const summary = await getSyncSummary(epoch);
  if (summary.rejected > 0) {
    return {kind: 'halted', reason: 'chain_broken'};
  }

  /*
   * Reconcile first. If a previous attempt's response was lost after the
   * server committed, the server's tip is already past some of our "pending"
   * events; marking those synced stops a blind resend from failing every one of
   * them as prev_hash_mismatch.
   */
  let reconciled = 0;
  try {
    const {data} = await apiClient.get('/attendance/sync/status', {
      params: {device_id: credentials.deviceId},
    });

    if (data?.last_chain_hash) {
      reconciled = await markSyncedThrough(epoch, data.last_chain_hash);
    }
  } catch (error) {
    const outcome = classifyError(error as any);
    return outcome.kind === 'halt'
      ? {kind: 'halted', reason: outcome.reason}
      : {kind: 'retry', reason: outcome.reason, sent: 0};
  }

  const pending = await listPendingEvents(epoch);

  if (pending.length === 0) {
    await recordLastSuccessfulSync(Date.now());
    return reconciled > 0
      ? {kind: 'synced', sent: 0, accepted: 0, flagged: 0, refused: 0, reconciled}
      : {kind: 'idle'};
  }

  let sent = 0;
  let accepted = 0;
  let flagged = 0;
  let refused = 0;

  for (let start = 0; start < pending.length; start += BATCH_SIZE) {
    const batch = pending.slice(start, start + BATCH_SIZE);
    const eventIds = batch.map(row => row.event_id);

    let response;
    try {
      response = await apiClient.post('/attendance/sync', {
        device_id: credentials.deviceId,
        events: batch.map(toWireEvent),
      });
    } catch (error) {
      const outcome = classifyError(error as any);

      await recordSyncAttempt(eventIds, credentials.deviceId, 'failed');

      // Progress from earlier batches in this run is already committed and
      // marked; only this and later batches are left pending for next time.
      return outcome.kind === 'halt'
        ? {kind: 'halted', reason: outcome.reason}
        : {kind: 'retry', reason: outcome.reason, sent};
    }

    const outcome = classifySuccess(response.data);

    if (outcome.kind !== 'processed') {
      return {kind: 'retry', reason: 'unexpected_response', sent};
    }

    await applyEventOutcomes(outcome.results);
    await recordSyncAttempt(eventIds, credentials.deviceId, 'synced');

    sent += batch.length;
    accepted += outcome.results.filter(r => r.status === 'accepted').length;
    flagged += outcome.results.filter(r => r.status === 'flagged').length;
    // Refused events do not halt: the server advanced its tip past them, so
    // the rest of the queue still links. They stay on the phone as refused.
    refused += outcome.results.filter(r => r.status === 'refused').length;

    // A rejection breaks the chain for every later batch. Stop here rather than
    // sending batches that are guaranteed to be rejected.
    if (outcome.results.some(r => r.status === 'rejected')) {
      return {kind: 'halted', reason: 'chain_broken'};
    }
  }

  await recordLastSuccessfulSync(Date.now());

  return {kind: 'synced', sent, accepted, flagged, refused, reconciled};
}
