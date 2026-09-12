/**
 * MonotonicClockService spec (Phase 5, layer 1 of the integrity engine —
 * named in STD TC-01).
 *
 * TurboModule rather than a legacy bridge module, because this project has
 * newArchEnabled=true and TurboModules support genuinely synchronous calls.
 * That matters here: the clock has to be read inline at the moment the
 * foreman taps, and an async bridge hop would let the value drift from the
 * event it is meant to timestamp.
 *
 * Codegen turns this file into the Kotlin abstract class
 * NativeHrisMonotonicClockSpec (package com.hrismobile.spec), which
 * HrisMonotonicClockModule implements. The file name must stay
 * Native*.ts for codegen to pick it up.
 *
 * @format
 */

import type {TurboModule} from 'react-native';
import {TurboModuleRegistry} from 'react-native';

export interface Spec extends TurboModule {
  /**
   * Milliseconds since boot, via Android's SystemClock.elapsedRealtime().
   * Counts through deep sleep and — the whole point — cannot be changed from
   * Settings, so it is unaffected by the wall-clock rollback TC-01 performs.
   *
   * Returned as a JS number: elapsedRealtime is a Long, but stays exact well
   * past 2^53 ms (~285,000 years of uptime), so the conversion is lossless.
   */
  getElapsedRealtime(): number;

  /**
   * Identifier for the current boot session.
   *
   * Needed because elapsedRealtime resets to ~0 on reboot, so the server can
   * only compare monotonic deltas within one session — otherwise every
   * legitimate restart looks like a monotonic regression, i.e. an attack.
   *
   * Derived from Settings.Global.BOOT_COUNT, a system-maintained counter.
   * Deliberately NOT derived from (currentTimeMillis - elapsedRealtime),
   * which is the obvious approach and is wrong: that expression shifts
   * whenever the wall clock is changed, so a clock rollback would also change
   * the boot id, the server would treat the record as a new boot session, skip
   * the drift comparison entirely, and TC-01 would silently pass the attack.
   */
  getBootId(): string;

  /**
   * Whether getBootId() is backed by the real system boot counter. False means
   * it fell back to a persisted heuristic, which is weaker — the server can
   * use this to decide how much to trust a boot-session boundary.
   */
  isBootIdSystemBacked(): boolean;
}

export default TurboModuleRegistry.getEnforcing<Spec>('HrisMonotonicClock');
