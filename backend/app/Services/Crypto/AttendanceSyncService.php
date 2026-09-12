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

    /**
     * @param  array<int, array<string, mixed>>  $events  in chain order
     * @return array{accepted:int, rejected:int, results:array<int, array<string, mixed>>}
     */
    public function ingest(DeviceKey $deviceKey, array $events): array
    {
        $events = array_values($events);

        // Gate 1, across the batch. Seeded with the device's last accepted
        // hash so a device cannot discard history and start a fresh chain.
        $chainResults = $this->chainVerifier->verifyChain(
            $events,
            $deviceKey->hmacKeyBytes(),
            $deviceKey->last_chain_hash,
        );

        $previousClock = $this->previousClockState($deviceKey);
        $results = [];
        $accepted = 0;
        $lastAcceptedHash = $deviceKey->last_chain_hash;

        foreach ($events as $index => $event) {
            $outcome = $this->evaluate($deviceKey, $event, $chainResults[$index], $previousClock);

            if ($outcome['accepted']) {
                $this->commit($deviceKey, $event);

                $accepted++;
                $lastAcceptedHash = $event['hmac_hash'];
                // Only accepted events advance the clock baseline. A rejected
                // record must not become the reference the next comparison is
                // made against, or one bad reading would poison the rest.
                $previousClock = [
                    'monotonic_timestamp' => $event['monotonic_timestamp'],
                    'time_in' => $event['time_in'] ?? null,
                    'boot_id' => $event['boot_id'],
                ];
            } else {
                $this->recordRejection($deviceKey, $event, $outcome['reason']);
            }

            $results[] = [
                'index' => $index,
                'accepted' => $outcome['accepted'],
                'reason' => $outcome['reason'],
            ];
        }

        $deviceKey->update([
            'last_chain_hash' => $lastAcceptedHash,
            'last_monotonic_timestamp' => $previousClock['monotonic_timestamp'] ?? null,
            'last_time_in' => $previousClock['time_in'] ?? null,
            'last_boot_id' => $previousClock['boot_id'] ?? null,
        ]);

        return [
            'accepted' => $accepted,
            'rejected' => count($events) - $accepted,
            'results' => $results,
        ];
    }

    /**
     * @return array{accepted:bool, reason:string|null}
     */
    private function evaluate(
        DeviceKey $deviceKey,
        array $event,
        array $chainResult,
        ?array $previousClock,
    ): array {
        if (! $chainResult['valid']) {
            return ['accepted' => false, 'reason' => $chainResult['reason']];
        }

        $signature = $this->signatureVerifier->verify(
            $this->payloadOf($event),
            (string) ($event['ecdsa_signature'] ?? ''),
            $deviceKey->public_key,
        );

        if (! $signature['valid']) {
            return ['accepted' => false, 'reason' => $signature['reason']];
        }

        $clock = $this->clockVerifier->verify($event, $previousClock);

        if (! $clock['valid']) {
            return ['accepted' => false, 'reason' => $clock['reason']];
        }

        return ['accepted' => true, 'reason' => null];
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
     * Commit one accepted event. Upserts the attendance row (one per employee
     * per day, last accepted event wins) and its 1:1 signature.
     */
    private function commit(DeviceKey $deviceKey, array $event): void
    {
        DB::transaction(function () use ($event) {
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
                    'verified' => true,
                ],
            );
        });
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
