/**
 * MonotonicClockService wrapper (Phase 5, layer 1).
 *
 * The single seam between app code and the native module. Screens and the
 * repository call this, never NativeHrisMonotonicClock directly, so the native
 * dependency sits behind one mockable boundary and the capture rule lives in
 * one place.
 *
 * @format
 */

import NativeHrisMonotonicClock from '../native/NativeHrisMonotonicClock';

export type ClockReading = {
  /** Wall clock, epoch ms. User-settable, therefore untrusted. */
  wallClockMs: number;
  /** Milliseconds since boot. Not settable from Settings. */
  monotonicMs: number;
  /** Boot session id — monotonic values are only comparable within one. */
  bootId: string;
  /** Whether bootId came from the system boot counter or the weaker fallback. */
  bootIdSystemBacked: boolean;
};

/**
 * Capture both clocks at the same instant.
 *
 * Both reads happen back to back and synchronously — that is the reason this
 * is a TurboModule rather than a legacy bridge module. An async hop between
 * the two reads would let them describe slightly different moments, and the
 * server's drift comparison would then be measuring our own latency rather
 * than clock tampering.
 */
export function captureClock(): ClockReading {
  const monotonicMs = NativeHrisMonotonicClock.getElapsedRealtime();
  const wallClockMs = Date.now();

  return {
    wallClockMs,
    monotonicMs,
    bootId: NativeHrisMonotonicClock.getBootId(),
    bootIdSystemBacked: NativeHrisMonotonicClock.isBootIdSystemBacked(),
  };
}

/**
 * The clock fields as the canonical payload expects them.
 *
 * monotonicMs is rounded because the payload refuses non-integers — PHP and
 * JS format floats differently, so a fractional millisecond would split the
 * canonical form between the two sides.
 */
export function clockFieldsForPayload(reading: ClockReading = captureClock()): {
  monotonic_timestamp: number;
  boot_id: string;
} {
  return {
    monotonic_timestamp: Math.round(reading.monotonicMs),
    boot_id: reading.bootId,
  };
}
