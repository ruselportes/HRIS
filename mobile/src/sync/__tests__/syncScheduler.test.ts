/**
 * Scheduler timing rules, with fake timers and an injected run function.
 *
 * @format
 */

import {SyncRunResult} from '../syncEngine';
import {SyncScheduler} from '../syncScheduler';

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(r => {
    resolve = r;
  });
  return {promise, resolve};
}

beforeEach(() => {
  jest.useFakeTimers();
});

afterEach(() => {
  jest.useRealTimers();
});

test('single-flight: concurrent calls join one run instead of starting two', async () => {
  // Two overlapping drains would send overlapping batches; the second would
  // fail as prev_hash_mismatch and mark accepted records rejected.
  const gate = deferred<SyncRunResult>();
  const run = jest.fn(() => gate.promise);
  const scheduler = new SyncScheduler({run});

  const a = scheduler.syncNow();
  const b = scheduler.syncNow();
  const c = scheduler.onConnectivityRegained();

  expect(run).toHaveBeenCalledTimes(1);
  expect(a).toBe(b);
  expect(b).toBe(c);

  gate.resolve({kind: 'idle'});
  await a;
});

test('a transient failure schedules a retry after the backoff delay', async () => {
  const run = jest
    .fn<Promise<SyncRunResult>, []>()
    .mockResolvedValueOnce({kind: 'retry', reason: 'network', sent: 0})
    .mockResolvedValueOnce({kind: 'idle'});

  const scheduler = new SyncScheduler({run, delay: () => 10_000, now: () => 0});

  await scheduler.syncNow();
  expect(scheduler.getState().nextRetryAt).toBe(10_000);

  jest.advanceTimersByTime(9_999);
  expect(run).toHaveBeenCalledTimes(1);

  jest.advanceTimersByTime(1);
  expect(run).toHaveBeenCalledTimes(2);
});

test('backoff grows across consecutive failures', async () => {
  const delays: number[] = [];
  const run = jest.fn(async (): Promise<SyncRunResult> => ({kind: 'retry', reason: 'network', sent: 0}));

  const scheduler = new SyncScheduler({
    run,
    delay: attempt => {
      delays.push(attempt);
      return 1_000;
    },
  });

  await scheduler.syncNow();
  // advanceTimersByTimeAsync flushes the promise chain between timer firings;
  // the synchronous variant fires the timer before the prior run has settled.
  await jest.advanceTimersByTimeAsync(1_000);
  await jest.advanceTimersByTimeAsync(1_000);

  expect(delays.slice(0, 3)).toEqual([0, 1, 2]);
});

test('reconnection pre-empts a long backoff and syncs immediately', async () => {
  // The SPMP targets < 5 s sync latency on reconnection. A retry timer backed
  // off to minutes must not stand between regaining signal and syncing.
  const run = jest
    .fn<Promise<SyncRunResult>, []>()
    .mockResolvedValueOnce({kind: 'retry', reason: 'network', sent: 0})
    .mockResolvedValueOnce({kind: 'synced', sent: 3, accepted: 3, flagged: 0, reconciled: 0});

  const scheduler = new SyncScheduler({run, delay: () => 5 * 60_000, now: () => 0});

  await scheduler.syncNow();
  expect(scheduler.getState().nextRetryAt).toBe(5 * 60_000);

  // Signal returns long before that retry would have fired.
  await scheduler.onConnectivityRegained();

  expect(run).toHaveBeenCalledTimes(2);
  expect(scheduler.getState().nextRetryAt).toBeNull();
  expect(scheduler.getState().attempt).toBe(0);
});

test('a halt does not schedule a retry', async () => {
  // A revoked device or broken chain will not fix itself on a timer.
  const run = jest.fn(async (): Promise<SyncRunResult> => ({kind: 'halted', reason: 'chain_broken'}));
  const scheduler = new SyncScheduler({run, delay: () => 1_000});

  await scheduler.syncNow();
  jest.advanceTimersByTime(60 * 60_000);

  expect(run).toHaveBeenCalledTimes(1);
  expect(scheduler.getState().nextRetryAt).toBeNull();
});

test('a successful sync resets the backoff', async () => {
  const run = jest
    .fn<Promise<SyncRunResult>, []>()
    .mockResolvedValueOnce({kind: 'retry', reason: 'network', sent: 0})
    .mockResolvedValueOnce({kind: 'synced', sent: 1, accepted: 1, flagged: 0, reconciled: 0});

  const scheduler = new SyncScheduler({run, delay: () => 1_000});

  await scheduler.syncNow();
  expect(scheduler.getState().attempt).toBe(1);

  await scheduler.syncNow();
  expect(scheduler.getState().attempt).toBe(0);
});

test('an unexpected throw is treated as retryable, never lost', async () => {
  const run = jest.fn(async (): Promise<SyncRunResult> => {
    throw new Error('SQLITE_BUSY');
  });
  const scheduler = new SyncScheduler({run, delay: () => 1_000});

  const result = await scheduler.syncNow();

  expect(result).toMatchObject({kind: 'retry', reason: 'unexpected_error'});
  expect(scheduler.getState().nextRetryAt).not.toBeNull();
});

test('stop() cancels a pending retry', async () => {
  const run = jest.fn(async (): Promise<SyncRunResult> => ({kind: 'retry', reason: 'network', sent: 0}));
  const scheduler = new SyncScheduler({run, delay: () => 1_000});

  await scheduler.syncNow();
  scheduler.stop();
  jest.advanceTimersByTime(10_000);

  expect(run).toHaveBeenCalledTimes(1);
});

test('subscribers see running state change', async () => {
  const gate = deferred<SyncRunResult>();
  const scheduler = new SyncScheduler({run: () => gate.promise});
  const seen: boolean[] = [];

  scheduler.subscribe(state => seen.push(state.running));
  const pending = scheduler.syncNow();

  expect(seen).toContain(true);

  gate.resolve({kind: 'idle'});
  await pending;

  expect(seen.at(-1)).toBe(false);
});
