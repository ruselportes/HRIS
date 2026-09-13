<?php

namespace App\Services\Crypto;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\CryptoSignature;
use App\Models\DeviceKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Server-side ingestion of a device's attendance batch (Phase 5, layer 4).
 *
 * Where the three verifiers meet. Each event must clear all three gates
 * before anything is committed:
 *
 *   1. HashChainVerifier  — the local log was not edited or reordered (TC-02)
 *   2. SignatureVerifier  — the payload came from this device's TEE  (TC-03)
 *   3. ClockIntegrityVerifier — the wall clock agrees with the monotonic
 *                               counter                              (TC-01)
 *
 * Order matters. The chain is checked across the whole batch first, because a
 * break at event 2 invalidates events 3+ regardless of how well they sign —
 * their prev_hash points into a history the server never accepted. Only then
 * is each surviving event checked individually.
 *
 * Rejections are never silent: every one writes an audit_log row naming which
 * gate failed. A record that vanishes without explanation is worse than one
 * that is refused loudly, because the foreman's work is gone either way and
 * only one of those is diagnosable.
 */
class AttendanceSyncService
{
    public function __construct(
        private HashChainVerifier $chainVerifier,
        private SignatureVerifier $signatureVerifier,
        private ClockIntegrityVerifier $clockVerifier,
    ) {}

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FLAGGED = 'flagged';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Three outcomes, not two — and the difference is the substance of this
     * method.
     *
     *   rejected — the chain or the signature failed. The DATA cannot be
     *     trusted (edited, reordered, or not from this device), so it is not
     *     committed and the chain tip does not move. Every later event in the
     *     batch necessarily fails linkage, because it chains onto something
     *     the server never accepted. (TC-02, TC-03)
     *
     *   flagged — chain and signature pass, but the wall clock disagrees with
     *     the monotonic counter. The data is authentic and correctly
     *     positioned; only its TIME is untrusted. So it is committed with
     *     verified = false, kept out of trusted data, audit-logged, and the
     *     chain tip DOES advance. TC-01 states this outright — "the hash chain
     *     remains continuous" — and the Sync Queue prototype independently
     *     draws the same line ("Flagged — sent for review" vs "Failed — not
     *     accepted").
     *
     *   accepted — every gate passed.
     *
     * Treating a clock failure as a rejection would be both wrong against the
     * STD and operationally destructive: one honest NTP jump past tolerance
     * would orphan every event the device ever produced afterwards.
     *
     * @param  array<int, array<string, mixed>>  $events  in chain order
     * @return array{accepted:int, flagged:int, rejected:int, last_chain_hash:string|null, results:array<int, array<string, mixed>>}
     */
    public function ingest(DeviceKey $deviceKey, array $events): array
    {
        $events = array_values($events);
        $hmacKey = $deviceKey->hmacKeyBytes();

        /*
         * The chain tip is tracked from what the server actually ADVANCED to,
         * rather than precomputed with HashChainVerifier::verifyChain(). That
         * method advances its own tip on every chain-valid event, independent
         * of the signature gate — so an event with a bad signature would still
         * let its successor pass linkage, and the tip would silently jump past
         * a rejected event. Coupling linkage to real acceptance closes that.
         */
        $tip = $deviceKey->last_chain_hash;
        $previousClock = $this->previousClockState($deviceKey);
        $rejectedEarlier = false;

        $results = [];
        $counts = [self::STATUS_ACCEPTED => 0, self::STATUS_FLAGGED => 0, self::STATUS_REJECTED => 0];

        foreach ($events as $index => $event) {
            $outcome = $this->evaluate($deviceKey, $event, $hmacKey, $tip, $previousClock, $rejectedEarlier);

            if ($outcome['status'] === self::STATUS_REJECTED) {
                $rejectedEarlier = true;
                $this->recordRejection($deviceKey, $event, $outcome['reason']);
            } else {
                $verified = $outcome['status'] === self::STATUS_ACCEPTED;

                $this->commit($event, $verified);

                if (! $verified) {
                    $this->recordFlag($deviceKey, $event, $outcome['reason'], $outcome['drift_seconds']);
                }

                // Both accepted and flagged advance the tip: the data and its
                // position are authentic in both cases.
                $tip = $event['hmac_hash'];

                // Only a TRUSTED clock becomes the next baseline. Advancing to
                // a flagged reading would let a rolled-back clock become the
                // reference for every later comparison, laundering the
                // rollback into looking like normal drift.
                if ($verified) {
                    $previousClock = [
                        'monotonic_timestamp' => $event['monotonic_timestamp'],
                        'time_in' => $event['time_in'] ?? null,
                        'boot_id' => $event['boot_id'],
                    ];
                }
            }

            $counts[$outcome['status']]++;

            $results[] = [
                'index' => $index,
                'status' => $outcome['status'],
                // Kept for callers that only care about "trusted": true for
                // accepted alone. status is the authoritative field.
                'accepted' => $outcome['status'] === self::STATUS_ACCEPTED,
                'reason' => $outcome['reason'],
                'hmac_hash' => $event['hmac_hash'] ?? null,
            ];
        }

        $deviceKey->update([
            'last_chain_hash' => $tip,
            'last_monotonic_timestamp' => $previousClock['monotonic_timestamp'] ?? null,
            'last_time_in' => $previousClock['time_in'] ?? null,
            'last_boot_id' => $previousClock['boot_id'] ?? null,
        ]);

        return [
            'accepted' => $counts[self::STATUS_ACCEPTED],
            'flagged' => $counts[self::STATUS_FLAGGED],
            'rejected' => $counts[self::STATUS_REJECTED],
            // Returned so the device can reconcile after a lost response —
            // see AttendanceSyncController::status().
            'last_chain_hash' => $tip,
            'results' => $results,
        ];
    }

    /**
     * @return array{status:string, reason:string|null, drift_seconds:int|null}
     */
    private function evaluate(
        DeviceKey $deviceKey,
        array $event,
        string $hmacKey,
        ?string $tip,
        ?array $previousClock,
        bool $rejectedEarlier,
    ): array {
        // Linkage against the tip the server actually holds.
        if (($event['prev_hash'] ?? null) !== $tip) {
            return [
                'status' => self::STATUS_REJECTED,
                // Distinguishes an innocent successor of a rejected event from
                // a genuine attempt to break or restart the chain.
                'reason' => $rejectedEarlier ? 'chain_broken_upstream' : 'prev_hash_mismatch',
                'drift_seconds' => null,
            ];
        }

        $expectedHmac = $this->chainVerifier->computeHmac($this->payloadOf($event), $hmacKey);

        if (! hash_equals($expectedHmac, (string) ($event['hmac_hash'] ?? ''))) {
            return ['status' => self::STATUS_REJECTED, 'reason' => 'hmac_mismatch', 'drift_seconds' => null];
        }

        $signature = $this->signatureVerifier->verify(
            $this->payloadOf($event),
            (string) ($event['ecdsa_signature'] ?? ''),
            $deviceKey->public_key,
        );

        if (! $signature['valid']) {
            return ['status' => self::STATUS_REJECTED, 'reason' => $signature['reason'], 'drift_seconds' => null];
        }

        $clock = $this->clockVerifier->verify($event, $previousClock);

        if (! $clock['valid']) {
            return [
                'status' => self::STATUS_FLAGGED,
                'reason' => $clock['reason'],
                'drift_seconds' => $clock['drift_seconds'],
            ];
        }

        return ['status' => self::STATUS_ACCEPTED, 'reason' => null, 'drift_seconds' => null];
    }

    /**
     * The canonical payload fields only. Anything else the device sent
     * (captured_at, override_flag) is deliberately excluded: the signature was
     * taken over exactly these fields in exactly this shape, so adding to them
     * here would break verification.
     */
    private function payloadOf(array $event): array
    {
        return [
            'employee_id' => $event['employee_id'],
            'crew_id' => $event['crew_id'],
            'date' => $event['date'],
            'status' => $event['status'],
            'time_in' => $event['time_in'] ?? null,
            'monotonic_timestamp' => $event['monotonic_timestamp'],
            'boot_id' => $event['boot_id'],
            'device_id' => $event['device_id'],
            'prev_hash' => $event['prev_hash'] ?? null,
        ];
    }

    private function previousClockState(DeviceKey $deviceKey): ?array
    {
        if ($deviceKey->last_monotonic_timestamp === null) {
            return null;
        }

        return [
            'monotonic_timestamp' => (int) $deviceKey->last_monotonic_timestamp,
            'time_in' => $deviceKey->last_time_in === null ? null : (int) $deviceKey->last_time_in,
            'boot_id' => $deviceKey->last_boot_id,
        ];
    }

    /**
     * Commit one accepted or flagged event. Upserts the attendance row (one per
     * employee per day) and its 1:1 signature.
     *
     * A flagged event never overwrites a row whose time is already trusted.
     * TC-01 is explicit that "no falsified time is committed as the effective
     * attendance time" — so an unverified reading can create a row where none
     * exists (marked verified = false, kept out of payroll), but cannot replace
     * one that was verified. A later ACCEPTED event does replace a flagged row,
     * since trusted data should supersede untrusted.
     */
    private function commit(array $event, bool $verified): void
    {
        DB::transaction(function () use ($event, $verified) {
            if (! $verified) {
                $existing = Attendance::query()
                    ->where('employee_id', $event['employee_id'])
                    ->where('date', $event['date'])
                    ->with('cryptoSignature')
                    ->first();

                if ($existing?->cryptoSignature?->verified === true) {
                    return;
                }
            }

            $attendance = Attendance::updateOrCreate(
                [
                    'employee_id' => $event['employee_id'],
                    'date' => $event['date'],
                ],
                [
                    'crew_id' => $event['crew_id'],
                    'status' => $event['status'],
                    // Epoch milliseconds on the wire; stored as a timestamp.
                    'time_in' => isset($event['time_in']) && $event['time_in'] !== null
                        ? Carbon::createFromTimestampMs((int) $event['time_in'])
                        : null,
                    'monotonic_timestamp' => $event['monotonic_timestamp'],
                    'sync_status' => 'synced',
                    'override_flag' => ! empty($event['override_flag']) ? '1' : null,
                ],
            );

            CryptoSignature::updateOrCreate(
                ['attendance_id' => $attendance->attendance_id],
                [
                    'hmac_hash' => $event['hmac_hash'],
                    'prev_hash' => $event['prev_hash'] ?? null,
                    'ecdsa_signature' => $event['ecdsa_signature'],
                    'verified' => $verified,
                ],
            );
        });
    }

    /**
     * A flagged event is committed but not trusted, so the audit entry is what
     * routes it to HR. The drift is recorded because "the clock was off by 7s"
     * and "the clock was set back two hours" call for very different responses.
     */
    private function recordFlag(DeviceKey $deviceKey, array $event, ?string $reason, ?int $driftSeconds): void
    {
        AuditLog::create([
            'actor_id' => $deviceKey->employee_id,
            'action_type' => 'ATTENDANCE_CLOCK_FLAGGED',
            'description' => sprintf(
                'Flagged attendance for employee %s on %s from device %s for HR review: %s%s. '
                .'Record kept, marked unverified, excluded from trusted data.',
                $event['employee_id'] ?? 'unknown',
                $event['date'] ?? 'unknown date',
                $deviceKey->device_id,
                $reason ?? 'unspecified',
                $driftSeconds !== null ? " (drift {$driftSeconds}s)" : '',
            ),
            'timestamp' => now(),
        ]);
    }

    /**
     * A rejected event is not written to tbl_attendance — that is the point,
     * no falsified time is committed. But the attempt is recorded, so a
     * rejection is investigable rather than just an absence.
     */
    private function recordRejection(DeviceKey $deviceKey, array $event, ?string $reason): void
    {
        AuditLog::create([
            'actor_id' => $deviceKey->employee_id,
            'action_type' => 'ATTENDANCE_VERIFICATION_FAILED',
            'description' => sprintf(
                'Rejected attendance for employee %s on %s from device %s: %s.',
                $event['employee_id'] ?? 'unknown',
                $event['date'] ?? 'unknown date',
                $deviceKey->device_id,
                $reason ?? 'unspecified',
            ),
            'timestamp' => now(),
        ]);
    }
}
