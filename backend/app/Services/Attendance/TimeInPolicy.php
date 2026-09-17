<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;

/**
 * Whether an event's credited time_in is permitted, given when the tap really
 * happened (Phase 7 — UC-05, STD TC-04).
 *
 * The clock verifier establishes that captured_at is trustworthy. This class
 * decides what time_in may be RELATIVE to it. The two are separate on purpose:
 * once the clock check moved onto captured_at, nothing else looked at time_in,
 * so without this rule a signed event could credit any arrival time at all.
 *
 *   no override   time_in must be the tap itself (within a few ms of capture)
 *   shift_credit  time_in must be exactly the day's shift start, the worker
 *                 must be Present, and the tap must be genuinely late — past
 *                 the grace window. The credit is not a free-form time.
 *   manual_time   the foreman states an arrival time: it must fall on that
 *                 day and cannot be later than the moment it was recorded.
 *
 * Absent and pending (Undo) carry no arrival, so neither a time nor an
 * override is meaningful on them.
 *
 * Plain arrays, no database, like the crypto verifiers — unit-testable in
 * isolation. Refusals are data errors from an authentic device, so they are
 * reported as reasons rather than thrown.
 */
class TimeInPolicy
{
    public const OVERRIDE_SHIFT_CREDIT = 'shift_credit';

    public const OVERRIDE_MANUAL_TIME = 'manual_time';

    /**
     * @param  array<string, mixed>  $event
     * @return array{valid:bool, reason:string|null}
     */
    public function evaluate(array $event): array
    {
        $status = $event['status'] ?? null;
        $timeIn = $event['time_in'] ?? null;
        $override = $event['override_type'] ?? null;

        if ($status === 'absent' || $status === 'pending') {
            if ($timeIn !== null) {
                return $this->refuse('time_in_without_arrival');
            }

            if ($override !== null) {
                return $this->refuse('override_without_arrival');
            }

            return $this->allow();
        }

        if ($timeIn === null) {
            return $this->refuse('arrival_without_time_in');
        }

        return match ($override) {
            null => $this->ordinaryTap($event),
            self::OVERRIDE_SHIFT_CREDIT => $this->shiftCredit($event),
            self::OVERRIDE_MANUAL_TIME => $this->manualTime($event),
            default => $this->refuse('unknown_override_type'),
        };
    }

    /**
     * What the phone needs to apply the same rules offline: when to offer the
     * late override and which instant to credit. Sent from here, beside the
     * rule that enforces it, so device and server cannot disagree.
     *
     * utc_offset_minutes lets the device compute "07:00 site time" without a
     * timezone database. Safe because Asia/Manila has no DST; if the site
     * timezone ever observed DST, the offset would need to be per date.
     *
     * @return array{start:string, late_override_grace_minutes:int, timezone:string, utc_offset_minutes:int}
     */
    public function shiftConfig(): array
    {
        return [
            'start' => config('attendance.shift_start', '07:00'),
            'late_override_grace_minutes' => (int) config('attendance.late_override_grace_minutes', 15),
            'timezone' => $this->timezone(),
            'utc_offset_minutes' => Carbon::now($this->timezone())->utcOffset(),
        ];
    }

    /** Epoch ms of the configured shift start on a date, in site time. */
    public function shiftStartMs(string $date): int
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $date.' '.config('attendance.shift_start', '07:00'),
            $this->timezone(),
        )->getTimestampMs();
    }

    private function ordinaryTap(array $event): array
    {
        $gapMs = abs((int) $event['time_in'] - (int) $event['captured_at']);

        if ($gapMs > $this->captureToleranceMs()) {
            return $this->refuse('time_in_does_not_match_tap');
        }

        return $this->allow();
    }

    private function shiftCredit(array $event): array
    {
        if ($event['status'] !== 'present') {
            // "Every worker you mark present gets the shift start time." A Late
            // worker plainly did not arrive at shift start, so crediting one
            // would contradict the status recorded alongside it.
            return $this->refuse('shift_credit_requires_present');
        }

        $shiftStart = $this->shiftStartMs($event['date']);

        if ((int) $event['time_in'] !== $shiftStart) {
            return $this->refuse('shift_credit_time_not_shift_start');
        }

        $capturedAt = (int) $event['captured_at'];

        if (! $this->isWithinDay($capturedAt, $event['date'])) {
            return $this->refuse('override_outside_day');
        }

        $graceMs = (int) config('attendance.late_override_grace_minutes', 15) * 60_000;

        if ($capturedAt < $shiftStart + $graceMs) {
            // Inside the grace window the foreman is not late, so real tap
            // times are available and must be used.
            return $this->refuse('shift_credit_not_late');
        }

        return $this->allow();
    }

    private function manualTime(array $event): array
    {
        $timeIn = (int) $event['time_in'];
        $capturedAt = (int) $event['captured_at'];

        if (! $this->isWithinDay($capturedAt, $event['date'])) {
            // Recording a past day is retroactive recovery (UC-07), which goes
            // through two web sign-offs rather than a phone.
            return $this->refuse('override_outside_day');
        }

        if (! $this->isWithinDay($timeIn, $event['date'])) {
            return $this->refuse('manual_time_outside_day');
        }

        if ($timeIn > $capturedAt + $this->captureToleranceMs()) {
            return $this->refuse('manual_time_in_future');
        }

        return $this->allow();
    }

    private function isWithinDay(int $epochMs, string $date): bool
    {
        $start = Carbon::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay();

        return $epochMs >= $start->getTimestampMs()
            && $epochMs < $start->copy()->addDay()->getTimestampMs();
    }

    private function captureToleranceMs(): int
    {
        return (int) config('attendance.time_in_capture_tolerance_seconds', 5) * 1000;
    }

    private function timezone(): string
    {
        return config('attendance.timezone', 'Asia/Manila');
    }

    private function allow(): array
    {
        return ['valid' => true, 'reason' => null];
    }

    private function refuse(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason];
    }
}
