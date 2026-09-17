<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Override events and their HR review (Phase 7 — UC-05, STD TC-04).
 *
 * An override event is ONE audit_log entry per foreman, crew and day, grouping
 * every worker that override credited. It is derived server-side from signed
 * events rather than sent by the phone as a separate message: the credit on
 * each record is already signed, so building the event from those records
 * means it cannot claim anything the records themselves do not.
 *
 * Built up incrementally, because a crew's roll call rarely arrives in one
 * sync. The event's timestamp is the EARLIEST real tap under it — the moment
 * the foreman applied the override — so TC-04's "a timestamp reflecting the
 * actual time of the action" holds however the records are batched.
 */
class OverrideEvents
{
    private const ACTION_FOR_OVERRIDE = [
        TimeInPolicy::OVERRIDE_SHIFT_CREDIT => AuditLog::LATE_OVERRIDE,
        TimeInPolicy::OVERRIDE_MANUAL_TIME => AuditLog::MANUAL_TIME_OVERRIDE,
    ];

    /**
     * Attach a just-committed overridden record to its event, creating the
     * event on first sight.
     */
    public function record(Attendance $attendance, string $overrideType, int $actorId): AuditLog
    {
        $actionType = self::ACTION_FOR_OVERRIDE[$overrideType]
            ?? throw new InvalidArgumentException("Unknown override type [{$overrideType}].");

        $capturedAt = $attendance->captured_at ?? Carbon::now();
        $date = substr((string) $attendance->date, 0, 10);

        $event = AuditLog::query()->firstOrCreate(
            [
                'actor_id' => $actorId,
                'action_type' => $actionType,
                'crew_id' => $attendance->crew_id,
                'subject_date' => $date,
            ],
            [
                'timestamp' => $capturedAt,
                'review_status' => AuditLog::REVIEW_PENDING,
                'description' => $this->describe($actionType, (int) $attendance->crew_id, $date),
            ],
        );

        if (! $event->wasRecentlyCreated) {
            $changes = [];

            if ($event->timestamp === null || $capturedAt->lt($event->timestamp)) {
                $changes['timestamp'] = $capturedAt;
            }

            /*
             * A record arriving after HR decided is not covered by that
             * decision — HR judged the records it could see. So the event
             * reopens rather than silently extending an approval to work
             * nobody reviewed. The earlier decision is kept in the description.
             */
            if ($event->review_status !== AuditLog::REVIEW_PENDING) {
                $changes['description'] = $event->description.sprintf(
                    ' Reopened %s: a record for employee %s synced after it was %s by %s.',
                    Carbon::now()->toDateTimeString(),
                    $attendance->employee_id,
                    $event->review_status,
                    $event->reviewer?->full_name ?? "employee {$event->reviewed_by}",
                );
                $changes['review_status'] = AuditLog::REVIEW_PENDING;
                $changes['reviewed_by'] = null;
                $changes['reviewed_at'] = null;
                $changes['review_note'] = null;
            }

            if ($changes !== []) {
                $event->update($changes);
            }
        }

        $attendance->update(['override_audit_id' => $event->audit_id]);

        return $event;
    }

    /**
     * HR's decision on an override event. A rejection must say why: it changes
     * what every worker under the event is paid, and the foreman will ask.
     */
    public function decide(AuditLog $event, Employee $reviewer, string $decision, ?string $note): AuditLog
    {
        if (! $event->isOverrideEvent()) {
            throw new InvalidArgumentException('Only override events can be reviewed.');
        }

        if (! in_array($decision, [AuditLog::REVIEW_APPROVED, AuditLog::REVIEW_REJECTED], true)) {
            throw new InvalidArgumentException("Unknown review decision [{$decision}].");
        }

        $event->update([
            'review_status' => $decision,
            'reviewed_by' => $reviewer->employee_id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $note,
        ]);

        return $event->refresh();
    }

    private function describe(string $actionType, int $crewId, string $date): string
    {
        return $actionType === AuditLog::LATE_OVERRIDE
            ? sprintf(
                'Late roll call: crew %s on %s credited from shift start (%s) instead of the tap time. '
                .'Awaiting HR review before payroll.',
                $crewId,
                $date,
                config('attendance.shift_start', '07:00'),
            )
            : sprintf(
                'Manual arrival times entered by the foreman for crew %s on %s. Awaiting HR review before payroll.',
                $crewId,
                $date,
            );
    }
}
