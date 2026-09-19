<?php

namespace App\Services\Crypto;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CryptoSignature;
use App\Models\DeviceKey;
use App\Services\Attendance\CrewLeadership;
use App\Services\Attendance\OverrideEvents;
use App\Services\Attendance\TimeInPolicy;
use App\Services\Attendance\TimeOutPolicy;
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
        private TimeInPolicy $timeInPolicy,
        private CrewLeadership $crewLeadership,
        private OverrideEvents $overrideEvents,
        private TimeOutPolicy $timeOutPolicy,
    ) {}

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FLAGGED = 'flagged';

    public const ROLL_CALL = 'roll_call';

    public const TIME_OUT = 'time_out';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_REJECTED = 'rejected';

    /**
     * crewLedBy(), resolved once per device per sync. A tampered batch orphans
     * every later event, so the same owner's crew is looked up once for the
     * whole batch rather than once per rejected row.
     *
     * @var array<int, int|null>
     */
    private array $attributedCrewByOwner = [];

    /**
     * Four outcomes — and the difference between them is the substance of this
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
     *   refused (Phase 7) — chain and signature pass, so the event is authentic
     *     and correctly positioned, but it is not PERMITTED: the device's
     *     foreman does not lead that crew (TC-05), or the credited time_in is
     *     not one TimeInPolicy allows. Not committed, audit-logged, and the tip
     *     DOES advance — the same reasoning as flagged. Refusing to advance
     *     would turn one unauthorised tap into chain_broken_upstream for every
     *     event the device ever sends afterwards, bricking its sync over a
     *     permission problem rather than an integrity one.
     *
     *   accepted — every gate passed.
     *
     * Treating a clock failure as a rejection would be both wrong against the
     * STD and operationally destructive: one honest NTP jump past tolerance
     * would orphan every event the device ever produced afterwards.
     *
     * @param  array<int, array<string, mixed>>  $events  in chain order
     * @return array{accepted:int, flagged:int, refused:int, rejected:int, last_chain_hash:string|null, results:array<int, array<string, mixed>>}
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
        $counts = [
            self::STATUS_ACCEPTED => 0,
            self::STATUS_FLAGGED => 0,
            self::STATUS_REFUSED => 0,
            self::STATUS_REJECTED => 0,
        ];

        foreach ($events as $index => $event) {
            $outcome = $this->evaluate($deviceKey, $event, $hmacKey, $tip, $previousClock, $rejectedEarlier);

            if ($outcome['status'] === self::STATUS_REJECTED) {
                $rejectedEarlier = true;
                $this->recordRejection($deviceKey, $event, $outcome['reason']);
            } elseif ($outcome['status'] === self::STATUS_REFUSED) {
                $this->recordRefusal($deviceKey, $event, $outcome['reason']);

                // Authentic and correctly chained, so its successors must still
                // link. The clock baseline does NOT move: a refused event is
                // never trusted data, so it should not become the reference
                // later events are judged against.
                $tip = $event['hmac_hash'];
            } else {
                $verified = $outcome['status'] === self::STATUS_ACCEPTED;

                $this->commit($deviceKey, $event, $verified);

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
                        'captured_at' => $event['captured_at'] ?? null,
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
            'last_captured_at' => $previousClock['captured_at'] ?? null,
            'last_boot_id' => $previousClock['boot_id'] ?? null,
        ]);

        return [
            'accepted' => $counts[self::STATUS_ACCEPTED],
            'flagged' => $counts[self::STATUS_FLAGGED],
            'refused' => $counts[self::STATUS_REFUSED],
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

        /*
         * Permission gates, after authenticity and before the clock. They only
         * mean anything once the event is known to be genuine, and a refusal
         * outranks a clock flag: an event that is not permitted must not be
         * committed at all, not even as unverified.
         */
        /*
         * Asked as of the capture, not of the sync: a tap taken offline before
         * the crew changed hands was the foreman's to take. captured_at is the
         * device's claim, but it is the same value the clock check below holds
         * against the monotonic clock, so backdating a tap to before a handover
         * surfaces as a clock flag rather than passing silently.
         */
        $capturedAt = Carbon::createFromTimestampMs((int) $event['captured_at']);

        if (! $this->crewLeadership->leads((int) $deviceKey->employee_id, (int) $event['crew_id'], $capturedAt)) {
            return ['status' => self::STATUS_REFUSED, 'reason' => 'not_crew_foreman', 'drift_seconds' => null];
        }

        // A roll call is judged on its own; a time-out against the record it
        // closes, which an earlier event in this batch may have just written.
        $policy = $this->eventTypeOf($event) === self::TIME_OUT
            ? $this->timeOutPolicy->evaluate($event, $this->storedRecord($event))
            : $this->timeInPolicy->evaluate($event);

        if (! $policy['valid']) {
            return ['status' => self::STATUS_REFUSED, 'reason' => $policy['reason'], 'drift_seconds' => null];
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
     * The canonical payload fields only — exactly the shape that was signed.
     * As of v2 that includes captured_at and override_type, which v1 left
     * unsigned; anything else the device sends stays outside the signature
     * and must not influence what is committed.
     */
    private function payloadOf(array $event): array
    {
        $version = AttendancePayload::versionOf($event);

        $payload = [
            'payload_version' => $version,
            'employee_id' => $event['employee_id'],
            'crew_id' => $event['crew_id'],
            'date' => $event['date'],
            'status' => $event['status'],
            'time_in' => $event['time_in'] ?? null,
            'captured_at' => $event['captured_at'],
            'override_type' => $event['override_type'] ?? null,
            'monotonic_timestamp' => $event['monotonic_timestamp'],
            'boot_id' => $event['boot_id'],
            'device_id' => $event['device_id'],
            'prev_hash' => $event['prev_hash'] ?? null,
        ];

        if ($version === 'v3') {
            $payload += [
                'event_type' => $event['event_type'],
                'time_out' => $event['time_out'] ?? null,
                'time_out_type' => $event['time_out_type'] ?? null,
            ];
        }

        return $payload;
    }

    /**
     * What an event records. Read from the signed v3 field; a v2 event can
     * only be a roll call, whatever unsigned keys it carries.
     */
    private function eventTypeOf(array $event): string
    {
        return AttendancePayload::versionOf($event) === 'v3' ? $event['event_type'] : self::ROLL_CALL;
    }

    private function previousClockState(DeviceKey $deviceKey): ?array
    {
        if ($deviceKey->last_monotonic_timestamp === null) {
            return null;
        }

        return [
            'monotonic_timestamp' => (int) $deviceKey->last_monotonic_timestamp,
            'captured_at' => $deviceKey->last_captured_at === null ? null : (int) $deviceKey->last_captured_at,
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
    private function commit(DeviceKey $deviceKey, array $event, bool $verified): void
    {
        if ($this->eventTypeOf($event) === self::TIME_OUT) {
            $this->commitTimeOut($deviceKey, $event, $verified);

            return;
        }

        DB::transaction(function () use ($deviceKey, $event, $verified) {
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
                    // The real tap. For an override this is what a rejection
                    // falls back to; for an ordinary tap it equals time_in.
                    'captured_at' => Carbon::createFromTimestampMs((int) $event['captured_at']),
                    'monotonic_timestamp' => $event['monotonic_timestamp'],
                    'sync_status' => 'synced',
                    // The override kind, not a bare boolean — shift_credit and
                    // manual_time are reviewed differently. Read from the
                    // signed override_type, never an unsigned flag.
                    'override_flag' => $event['override_type'] ?? null,
                    // Cleared here and re-linked below if this event is itself an
                    // override. An ordinary re-tap supersedes an earlier credit,
                    // so the worker must leave that override's review.
                    'override_audit_id' => null,
                    // A new roll call — a re-mark or an Undo — closes nothing:
                    // any time-out recorded against the earlier status goes.
                    'time_out' => null,
                    'time_out_type' => null,
                    'time_out_captured_at' => null,
                    'time_out_audit_id' => null,
                ],
            );

            if (! empty($event['override_type'])) {
                $this->overrideEvents->record($attendance, $event['override_type'], (int) $deviceKey->employee_id);
            }

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
     * Apply a time-out to the record it closes.
     *
     * Only a trusted time-out is applied. A flagged one is audit-logged by the
     * caller and otherwise ignored, so payroll treats the day as having no
     * time-out rather than paying on an untrusted clock. And a time-out never
     * touches the record's signature ledger entry: that entry vouches for the
     * roll call — status and time in — and a verified time-out must not make a
     * flagged time in look trusted.
     */
    private function commitTimeOut(DeviceKey $deviceKey, array $event, bool $verified): void
    {
        if (! $verified) {
            return;
        }

        DB::transaction(function () use ($deviceKey, $event) {
            $record = $this->storedRecord($event);
            $cleared = $event['time_out'] === null;

            $record->update([
                'time_out' => $cleared ? null : Carbon::createFromTimestampMs((int) $event['time_out']),
                'time_out_type' => $event['time_out_type'],
                'time_out_captured_at' => $cleared ? null : Carbon::createFromTimestampMs((int) $event['captured_at']),
                'time_out_audit_id' => null,
            ]);

            if ($event['time_out_type'] === TimeOutPolicy::MANUAL_TIME) {
                $this->overrideEvents->recordTimeOut($record, (int) $deviceKey->employee_id);
            }
        });
    }

    private function storedRecord(array $event): ?Attendance
    {
        return Attendance::query()
            ->where('employee_id', $event['employee_id'])
            ->where('date', $event['date'])
            ->first();
    }

    /**
     * A flagged event is committed but not trusted, so the audit entry is what
     * routes it to HR. The drift is recorded because "the clock was off by 7s"
     * and "the clock was set back two hours" call for very different responses.
     *
     * The event's signature verified, so its crew and day are trustworthy and
     * stored for grouping — a flagged event is charged to the crew it claims.
     */
    private function recordFlag(DeviceKey $deviceKey, array $event, ?string $reason, ?int $driftSeconds): void
    {
        AuditLog::create([
            'actor_id' => $deviceKey->employee_id,
            'action_type' => AuditLog::ATTENDANCE_CLOCK_FLAGGED,
            'crew_id' => $event['crew_id'] ?? null,
            'subject_date' => $event['date'] ?? null,
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
     * A refused event came from a genuine device but was not permitted. Kept
     * distinct from ATTENDANCE_VERIFICATION_FAILED because the response is
     * different: a failed verification suggests tampering, while a refusal is
     * usually a stale roster or a misapplied override that someone should talk
     * to the foreman about.
     *
     * The event is authentic, so its claimed crew is stored — but it is the
     * crew the foreman CLAIMED, not necessarily the one led. With
     * not_crew_foreman that is exactly a crew the device does not lead, and a
     * crafted event can even name a crew that no longer exists. crew_id is a
     * foreign key, so a missing crew must not sink the whole sync: it is
     * stored only when the crew exists, and the claimed id always stays in the
     * description. Refusals are never counted as integrity failures.
     */
    private function recordRefusal(DeviceKey $deviceKey, array $event, ?string $reason): void
    {
        $claimedCrewId = (int) ($event['crew_id'] ?? 0);

        AuditLog::create([
            'actor_id' => $deviceKey->employee_id,
            'action_type' => AuditLog::ATTENDANCE_REFUSED,
            'crew_id' => Crew::query()->whereKey($claimedCrewId)->exists() ? $claimedCrewId : null,
            'subject_date' => $event['date'] ?? null,
            'description' => sprintf(
                'Refused attendance for employee %s, crew %s on %s from device %s: %s. '
                .'Authentic and chained, but not permitted; not committed.',
                $event['employee_id'] ?? 'unknown',
                $claimedCrewId,
                $event['date'] ?? 'unknown date',
                $deviceKey->device_id,
                $reason ?? 'unspecified',
            ),
            'timestamp' => now(),
        ]);
    }

    /**
     * A rejected event is not written to tbl_attendance — that is the point,
     * no falsified time is committed. But the attempt is recorded, so a
     * rejection is investigable rather than just an absence.
     *
     * The event failed its chain or signature check, so its crew and date are
     * exactly the fields that may have been tampered with: neither is trusted
     * onto the row. subject_date stays null, and the crew is attributed from
     * the device OWNER — the crew they led at server receive time, via
     * CrewLeadership — never from the payload. A forged event must not be able
     * to charge another site's compliance score. If the owner led no crew or
     * more than one at that instant, crew_id stays null too: the incident
     * still counts, it is just not charged to any site.
     */
    private function recordRejection(DeviceKey $deviceKey, array $event, ?string $reason): void
    {
        $receivedAt = now();

        AuditLog::create([
            'actor_id' => $deviceKey->employee_id,
            'action_type' => AuditLog::ATTENDANCE_VERIFICATION_FAILED,
            'crew_id' => $this->attributedCrew($deviceKey, $receivedAt),
            'subject_date' => null,
            'description' => sprintf(
                'Rejected attendance for employee %s on %s from device %s: %s.',
                $event['employee_id'] ?? 'unknown',
                $event['date'] ?? 'unknown date',
                $deviceKey->device_id,
                $reason ?? 'unspecified',
            ),
            'timestamp' => $receivedAt,
        ]);
    }

    /**
     * The crew the device owner led at receive time — resolved once per sync
     * and reused for every rejected row of the batch.
     */
    private function attributedCrew(DeviceKey $deviceKey, Carbon $receivedAt): ?int
    {
        $ownerId = (int) $deviceKey->employee_id;

        if (! array_key_exists($ownerId, $this->attributedCrewByOwner)) {
            $this->attributedCrewByOwner[$ownerId] = $this->crewLeadership->crewLedBy($ownerId, $receivedAt);
        }

        return $this->attributedCrewByOwner[$ownerId];
    }
}
