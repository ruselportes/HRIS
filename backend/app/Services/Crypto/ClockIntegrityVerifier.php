<?php

namespace App\Services\Crypto;

/**
 * Monotonic-vs-wall-clock cross-check (Phase 5, TC-01 clock rollback).
 *
 * The device reports two independent times per record: `captured_at` (wall
 * clock at the tap, user-settable, therefore untrusted) and
 * `monotonic_timestamp` (elapsedRealtime — milliseconds since boot, not
 * settable from Settings).
 *
 * Why captured_at and not time_in (Phase 7): time_in is what the worker is
 * CREDITED with, which a late foreman override deliberately sets to 07:00
 * while the tap really happens at 09:20. Checked against time_in, that is
 * indistinguishable from a rollback, so every legitimate override would be
 * flagged. captured_at is when the tap actually happened — the reading the
 * monotonic counter is measuring. Whether time_in is allowed to differ from it
 * is TimeInPolicy's decision, not this class's. It also means an Absent worker
 * is now clock-checked too: no time_in, but still a captured_at.
 *
 * Between two consecutive records from the same boot session, both clocks
 * should advance by the same amount. If the wall clock disagrees with the
 * monotonic counter by more than the configured tolerance, the wall clock was
 * changed — which is exactly TC-01's two-hour rollback.
 *
 * Boot boundaries: elapsedRealtime resets to ~0 on reboot, so a naive
 * "monotonic must always increase" rule would flag every legitimate restart
 * as an attack. Records therefore carry a `boot_id`; monotonic deltas are only
 * meaningful within one boot session, and a boot change is reported as a
 * known discontinuity rather than tampering. Chain continuity across the
 * boundary is still guaranteed by prev_hash, so a reboot cannot be used to
 * smuggle in a forged history.
 */
class ClockIntegrityVerifier
{
    /**
     * @param  array<string, mixed>  $record  the record being checked
     * @param  array<string, mixed>|null  $previous  last accepted record for this
     *                                               device, or null if this is the device's first
     * @return array{valid:bool, reason:string|null, drift_seconds:int|null}
     */
    public function verify(array $record, ?array $previous): array
    {
        if ($previous === null) {
            // Nothing to compare against. The first record from a device
            // establishes the baseline; it cannot itself be checked for
            // rollback, which is why device binding is the trust anchor.
            return ['valid' => true, 'reason' => 'baseline', 'drift_seconds' => null];
        }

        if (($record['boot_id'] ?? null) !== ($previous['boot_id'] ?? null)) {
            return [
                'valid' => true,
                'reason' => 'boot_session_changed',
                'drift_seconds' => null,
            ];
        }

        $monotonicDelta = (int) $record['monotonic_timestamp'] - (int) $previous['monotonic_timestamp'];

        if ($monotonicDelta < 0) {
            // Same boot session but the counter went backwards. elapsedRealtime
            // cannot do this, so the value was fabricated.
            return [
                'valid' => false,
                'reason' => 'monotonic_regressed',
                'drift_seconds' => null,
            ];
        }

        // Every v2 event carries captured_at, so this only fires for a baseline
        // migrated from a device that never recorded one. The monotonic
        // ordering above still applies.
        if (($record['captured_at'] ?? null) === null || ($previous['captured_at'] ?? null) === null) {
            return ['valid' => true, 'reason' => 'no_wall_clock_to_compare', 'drift_seconds' => null];
        }

        $wallDelta = (int) $record['captured_at'] - (int) $previous['captured_at'];

        // Both deltas are in milliseconds; drift is their disagreement.
        $driftSeconds = (int) round(abs($wallDelta - $monotonicDelta) / 1000);

        $tolerance = (int) config('crypto.clock_skew_tolerance_seconds', 120);

        if ($driftSeconds > $tolerance) {
            return [
                'valid' => false,
                // Distinguish the two directions: a backwards wall clock is the
                // classic "log me in earlier than I arrived" attack, while a
                // forwards jump is likelier to be misconfiguration. Both are
                // rejected, but the audit trail should not conflate them.
                'reason' => $wallDelta < 0 ? 'wall_clock_rolled_back' : 'wall_clock_jumped_forward',
                'drift_seconds' => $driftSeconds,
            ];
        }

        return ['valid' => true, 'reason' => null, 'drift_seconds' => $driftSeconds];
    }
}
