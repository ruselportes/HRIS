/**
 * Sync engine orchestration. The repository is mocked so what is under test is
 * the ORDER and the DECISIONS: reconcile before sending, send in chain order,
 * stop on a rejection, halt without touching the network on a broken chain.
 *
 * @format
 */

import {apiClient} from '../../api/client';
import * as credentials from '../../crypto/deviceCredentials';
import * as repo from '../../db/attendanceRepository';
import {BATCH_SIZE, runSync} from '../syncEngine';

jest.mock('../../db/attendanceRepository', () => ({
  applyEventOutcomes: jest.fn().mockResolvedValue(undefined),
  getSyncSummary: jest.fn(),
  listPendingEvents: jest.fn(),
  markSyncedThrough: jest.fn().mockResolvedValue(0),
  recordLastSuccessfulSync: jest.fn().mockResolvedValue(undefined),
  recordSyncAttempt: jest.fn().mockResolvedValue(undefined),
}));

const CREDS = {deviceId: 'dev-sync-0001', hmacKeyBase64: 'a2V5'};

function row(n: number) {
  return {
    event_id: n,
    employee_id: 100 + n,
    crew_id: 7,
    date: '2026-09-13',
    status: 'present',
    time_in: 1789200000000 + n,
    monotonic_timestamp: 86_400_000 + n,
    boot_id: 'bc7',
    device_id: CREDS.deviceId,
    prev_hash: n === 1 ? null : `h${n - 1}`,
    hmac_hash: `h${n}`,
    ecdsa_signature: `s${n}`,
    override_flag: 0,
    captured_at: 1789200000000 + n,
  };
}

function summary(overrides: Partial<{rejected: number}> = {}) {
  return {pending: 0, synced: 0, flagged: 0, rejected: 0, lastSuccessfulSync: null, rows: [], ...overrides};
}

function accepting(rows: any[]) {
  return {
    data: {
      last_chain_hash: rows.at(-1)?.hmac_hash ?? null,
      results: rows.map(r => ({hmac_hash: r.hmac_hash, status: 'accepted', reason: null})),
    },
  };
}

let getSpy: jest.SpyInstance;
let postSpy: jest.SpyInstance;

beforeEach(() => {
  jest.clearAllMocks();

  jest.spyOn(credentials, 'loadCredentials').mockResolvedValue(CREDS);
  (repo.getSyncSummary as jest.Mock).mockResolvedValue(summary());
  (repo.listPendingEvents as jest.Mock).mockResolvedValue([]);

  getSpy = jest.spyOn(apiClient, 'get').mockResolvedValue({data: {last_chain_hash: null}} as any);
  postSpy = jest.spyOn(apiClient, 'post');
});

afterEach(() => {
  jest.restoreAllMocks();
});

test('an unbound device halts without any network call', async () => {
  jest.spyOn(credentials, 'loadCredentials').mockResolvedValue(null);

  expect(await runSync()).toEqual({kind: 'halted', reason: 'unbound'});
  expect(getSpy).not.toHaveBeenCalled();
  expect(postSpy).not.toHaveBeenCalled();
});

test('a broken chain halts before touching the network', async () => {
  // Sending more of a chain the server has already refused cannot succeed.
  (repo.getSyncSummary as jest.Mock).mockResolvedValue(summary({rejected: 1}));

  expect(await runSync()).toEqual({kind: 'halted', reason: 'chain_broken'});
  expect(getSpy).not.toHaveBeenCalled();
  expect(postSpy).not.toHaveBeenCalled();
});

test('nothing pending is idle', async () => {
  expect(await runSync()).toEqual({kind: 'idle'});
  expect(postSpy).not.toHaveBeenCalled();
});

test('reconciles against the server tip BEFORE sending', async () => {
  // The lost-response recovery depends on this ordering.
  const order: string[] = [];
  getSpy.mockImplementation(async () => {
    order.push('status');
    return {data: {last_chain_hash: 'h2'}};
  });
  (repo.markSyncedThrough as jest.Mock).mockImplementation(async () => {
    order.push('reconcile');
    return 2;
  });
  (repo.listPendingEvents as jest.Mock).mockImplementation(async () => {
    order.push('list');
    return [row(3)];
  });
  postSpy.mockImplementation(async () => {
    order.push('send');
    return accepting([row(3)]);
  });

  await runSync();

  expect(order).toEqual(['status', 'reconcile', 'list', 'send']);
  expect(repo.markSyncedThrough).toHaveBeenCalledWith(expect.any(String), 'h2');
});

test('a fully reconciled queue with nothing left to send reports what it reconciled', async () => {
  getSpy.mockResolvedValue({data: {last_chain_hash: 'h3'}});
  (repo.markSyncedThrough as jest.Mock).mockResolvedValue(3);

  expect(await runSync()).toEqual({
    kind: 'synced',
    sent: 0,
    accepted: 0,
    flagged: 0,
    reconciled: 3,
  });
});

test('sends events in chain order with the exact wire shape', async () => {
  const rows = [row(1), row(2)];
  (repo.listPendingEvents as jest.Mock).mockResolvedValue(rows);
  postSpy.mockResolvedValue(accepting(rows));

  await runSync();

  const [, body] = postSpy.mock.calls[0];
  expect(body.device_id).toBe(CREDS.deviceId);
  expect(body.events.map((e: any) => e.hmac_hash)).toEqual(['h1', 'h2']);
  // time_in must be PRESENT even when null; override_flag becomes a boolean.
  expect(body.events[0]).toHaveProperty('time_in');
  expect(body.events[0].override_flag).toBe(false);
});

test('an absent worker is sent with time_in present as null, not omitted', async () => {
  const absent = {...row(1), status: 'absent', time_in: null};
  (repo.listPendingEvents as jest.Mock).mockResolvedValue([absent]);
  postSpy.mockResolvedValue(accepting([absent]));

  await runSync();

  const event = postSpy.mock.calls[0][1].events[0];
  expect('time_in' in event).toBe(true);
  expect(event.time_in).toBeNull();
});

test('splits a large queue into batches, in order', async () => {
  const rows = Array.from({length: BATCH_SIZE + 5}, (_, i) => row(i + 1));
  (repo.listPendingEvents as jest.Mock).mockResolvedValue(rows);
  postSpy.mockImplementation(async (_url, body: any) =>
    accepting(body.events.map((e: any) => ({hmac_hash: e.hmac_hash}))),
  );

  const result = await runSync();

  expect(postSpy).toHaveBeenCalledTimes(2);
  expect(postSpy.mock.calls[0][1].events).toHaveLength(BATCH_SIZE);
  expect(postSpy.mock.calls[1][1].events).toHaveLength(5);
  expect(result).toMatchObject({kind: 'synced', sent: BATCH_SIZE + 5});
});

test('applies the server verdict to each event', async () => {
  const rows = [row(1), row(2)];
  (repo.listPendingEvents as jest.Mock).mockResolvedValue(rows);
  postSpy.mockResolvedValue({
    data: {
      results: [
        {hmac_hash: 'h1', status: 'accepted', reason: null},
        {hmac_hash: 'h2', status: 'flagged', reason: 'wall_clock_rolled_back'},
      ],
    },
  });

  const result = await runSync();

  expect(repo.applyEventOutcomes).toHaveBeenCalledWith([
    {hmacHash: 'h1', status: 'accepted', reason: null},
    {hmacHash: 'h2', status: 'flagged', reason: 'wall_clock_rolled_back'},
  ]);
  // A flag does not break the chain, so the run still completes.
  expect(result).toMatchObject({kind: 'synced', accepted: 1, flagged: 1});
});

test('a rejection stops further batches instead of sending ones that must fail', async () => {
  const rows = Array.from({length: BATCH_SIZE + 5}, (_, i) => row(i + 1));
  (repo.listPendingEvents as jest.Mock).mockResolvedValue(rows);
  postSpy.mockResolvedValueOnce({
    data: {
      results: [
        {hmac_hash: 'h1', status: 'accepted', reason: null},
        {hmac_hash: 'h2', status: 'rejected', reason: 'hmac_mismatch'},
      ],
    },
  });

  const result = await runSync();

  expect(result).toEqual({kind: 'halted', reason: 'chain_broken'});
  // The first batch's verdicts are still recorded...
  expect(repo.applyEventOutcomes).toHaveBeenCalledTimes(1);
  // ...but the second batch is never sent.
  expect(postSpy).toHaveBeenCalledTimes(1);
});

test('a network failure mid-run keeps earlier progress and asks to retry', async () => {
  const rows = Array.from({length: BATCH_SIZE + 5}, (_, i) => row(i + 1));
  (repo.listPendingEvents as jest.Mock).mockResolvedValue(rows);
  postSpy
    .mockImplementationOnce(async (_url, body: any) =>
      accepting(body.events.map((e: any) => ({hmac_hash: e.hmac_hash}))),
    )
    .mockRejectedValueOnce({});

  const result = await runSync();

  expect(result).toEqual({kind: 'retry', reason: 'network', sent: BATCH_SIZE});
  expect(repo.recordSyncAttempt).toHaveBeenCalledWith(
    expect.any(Array),
    CREDS.deviceId,
    'failed',
  );
});

test('a revoked device mid-run halts', async () => {
  (repo.listPendingEvents as jest.Mock).mockResolvedValue([row(1)]);
  postSpy.mockRejectedValue({response: {status: 403, data: {reason: 'device_revoked'}}});

  expect(await runSync()).toEqual({kind: 'halted', reason: 'device_revoked'});
});

test('the status check failing offline is a retry, not a halt', async () => {
  getSpy.mockRejectedValue({});

  expect(await runSync()).toEqual({kind: 'retry', reason: 'network', sent: 0});
  expect(postSpy).not.toHaveBeenCalled();
});

test('records the last successful sync time on completion', async () => {
  (repo.listPendingEvents as jest.Mock).mockResolvedValue([row(1)]);
  postSpy.mockResolvedValue(accepting([row(1)]));

  await runSync();

  expect(repo.recordLastSuccessfulSync).toHaveBeenCalled();
});
