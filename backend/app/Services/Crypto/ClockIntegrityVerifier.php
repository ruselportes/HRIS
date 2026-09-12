<?php

namespace App\Services\Crypto;

/**
 * Monotonic-vs-wall-clock cross-check (Phase 5, TC-01 clock rollback).
 *
 * The device reports two independent times per record: `time_in` (wall clock,
 * user-settable, therefore untrusted) and `monotonic_timestamp`
 * (elapsedRealtime — milliseconds since boot, not settable from Settings).
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

        // An Absent worker has no time_in, so there is no wall clock to
        // cross-check. The monotonic ordering above still applies.
        if ($record['time_in'] === null || $previous['time_in'] === null) {
            return ['valid' => true, 'reason' => 'no_wall_clock_to_compare', 'drift_seconds' => null];
        }

        $wallDelta = (int) $record['time_in'] - (int) $previous['time_in'];

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
