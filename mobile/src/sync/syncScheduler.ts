/**
 * When sync runs (Phase 6): on reconnection, on returning to the foreground,
 * on demand, and on a backed-off retry after a transient failure.
 *
 * Three rules this file exists to enforce:
 *
 * 1. SINGLE-FLIGHT. A reconnect event, an app-foreground event and a manual tap
 *    can all arrive together. Two concurrent drains would send overlapping
 *    batches, the second would fail as prev_hash_mismatch, and accepted records
 *    would be marked rejected. A call made while a run is in progress joins that
 *    run instead of starting another.
 *
 * 2. RECONNECT PRE-EMPTS BACKOFF. The SPMP target is sync latency under 5
 *    seconds on reconnection. A retry timer backed off to several minutes would
 *    blow that, so regaining signal cancels the pending retry and syncs now.
 *
 * 3. HALTS DO NOT RETRY. A revoked device, an expired sign-in or a broken chain
 *    will not fix itself on a timer, and retrying would just keep the queue
 *    looking busy while the foreman assumes all is well.
 *
 * Dependencies are injectable so the timing rules can be tested with fake
 * timers and a fake run function.
 *
 * @format
 */

import {backoffDelay} from './backoff';
import {SyncRunResult, runSync} from './syncEngine';

export type SchedulerState = {
  running: boolean;
  lastResult: SyncRunResult | null;
  /** Epoch ms of the next scheduled retry, or null if none is pending. */
  nextRetryAt: number | null;
  attempt: number;
};

type Deps = {
  run: () => Promise<SyncRunResult>;
  delay: (attempt: number) => number;
  now: () => number;
};

export class SyncScheduler {
  private inFlight: Promise<SyncRunResult> | null = null;
  private retryTimer: ReturnType<typeof setTimeout> | null = null;
  private attempt = 0;
  private lastResult: SyncRunResult | null = null;
  private nextRetryAt: number | null = null;
  private readonly listeners = new Set<(state: SchedulerState) => void>();
  private readonly deps: Deps;

  constructor(deps: Partial<Deps> = {}) {
    this.deps = {
      run: deps.run ?? runSync,
      delay: deps.delay ?? (attempt => backoffDelay(attempt)),
      now: deps.now ?? Date.now,
    };
  }

  getState(): SchedulerState {
    return {
      running: this.inFlight !== null,
      lastResult: this.lastResult,
      nextRetryAt: this.nextRetryAt,
      attempt: this.attempt,
    };
  }

  subscribe(listener: (state: SchedulerState) => void): () => void {
    this.listeners.add(listener);
    listener(this.getState());
    return () => this.listeners.delete(listener);
  }

  /** Run now. Joins an in-progress run rather than starting a concurrent one. */
  syncNow(): Promise<SyncRunResult> {
    if (this.inFlight) {
      return this.inFlight;
    }

    this.clearRetry();

    this.inFlight = this.deps
      .run()
      .catch(
        // An unexpected throw (a SQLite error, say) is treated as transient.
        // The records are still on the phone; retrying loses nothing.
        (): SyncRunResult => ({kind: 'retry', reason: 'unexpected_error', sent: 0}),
      )
      .then(result => {
        this.handleResult(result);
        return result;
      })
      .finally(() => {
        this.inFlight = null;
        this.emit();
      });

    this.emit();
    return this.inFlight;
  }

  /**
   * Connectivity regained. Pre-empts any backed-off retry and resets the
   * backoff, because the condition that caused it (no signal) has just ended.
   */
  onConnectivityRegained(): Promise<SyncRunResult> {
    this.attempt = 0;
    return this.syncNow();
  }

  /** Stop all scheduled work — call on sign-out. */
  stop(): void {
    this.clearRetry();
    this.attempt = 0;
    this.emit();
  }

  private handleResult(result: SyncRunResult): void {
    this.lastResult = result;

    if (result.kind === 'retry') {
      const wait = this.deps.delay(this.attempt);
      this.attempt += 1;
      this.nextRetryAt = this.deps.now() + wait;
      this.retryTimer = setTimeout(() => {
        this.retryTimer = null;
        this.nextRetryAt = null;
        this.syncNow();
      }, wait);
      return;
    }

    // synced, idle, or halted: nothing further is scheduled. A halt in
    // particular must not retry — see rule 3.
    this.attempt = 0;
  }

  private clearRetry(): void {
    if (this.retryTimer !== null) {
      clearTimeout(this.retryTimer);
      this.retryTimer = null;
    }
    this.nextRetryAt = null;
  }

  private emit(): void {
    const state = this.getState();
    this.listeners.forEach(listener => listener(state));
  }
}

/** The app's single scheduler. One instance, so single-flight holds app-wide. */
export const syncScheduler = new SyncScheduler();
