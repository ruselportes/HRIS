<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use Illuminate\Support\Carbon;

/**
 * Whether a time-out event is permitted (time-out capture, payload v3).
 *
 * A time-out closes a day already on roll call, so unlike TimeInPolicy this
 * judges the event against the record it would change:
 *
 *   ordinary (null)  "Out" tapped as the worker leaves: time_out is the tap,
 *                    within a few seconds of captured_at.
 *   shift_end        Close shift: time_out is exactly the day's shift end, and
 *                    the tap is at or after it — crediting 16:00 at 15:00
 *                    would pay for an hour nobody has worked yet.
 *   manual_time      a time the foreman states ("forgot to tap out"): on that
 *                    day, after the time in, and not after the moment it was
 *                    entered. Reviewed by HR, like a manual time in.
 *
 * A time-out with time_out null clears an earlier one (Undo). Absent and
 * pending workers have no arrival, so there is nothing to close.
 */
class TimeOutPolicy
{
    public const SHIFT_END = 'shift_end';

    public const MANUAL_TIME = 'manual_time';

    /**
     * @param  array<string, mixed>  $event
     * @param  Attendance|null  $record  the stored record for the worker and day
     * @return array{valid:bool, reason:string|null}
     */
    public function evaluate(array $event, ?Attendance $record): array
    {
        // A time-out restates nothing about arrival: that is the roll call's.
        if (($event['time_in'] ?? null) !== null || ($event['override_type'] ?? null) !== null) {
            return $this->refuse('time_out_carries_time_in');
        }

        $timeOut = $event['time_out'] ?? null;
        $type = $event['time_out_type'] ?? null;

        if ($timeOut === null) {
            return $type === null ? $this->allow() : $this->refuse('clearing_with_time_out_type');
        }

        if ($record === null || ! in_array($record->status, ['present', 'late'], true)) {
            return $this->refuse('time_out_without_arrival');
        }

        if ($event['status'] !== $record->status) {
            return $this->refuse('time_out_status_mismatch');
        }

        $timeOut = (int) $timeOut;
        $capturedAt = (int) $event['captured_at'];
        $date = (string) $event['date'];

        if (! $this->isWithinDay($capturedAt, $date)) {
            // A day already over is recovery's (UC-07), not the phone's.
            return $this->refuse('override_outside_day');
        }

        if (! $this->isWithinDay($timeOut, $date)) {
            return $this->refuse('time_out_outside_day');
        }

        if ($record->time_in !== null && $timeOut <= $record->time_in->getTimestampMs()) {
            return $this->refuse('time_out_before_time_in');
        }

        return match ($type) {
            null => abs($timeOut - $capturedAt) > $this->toleranceMs()
                ? $this->refuse('time_out_does_not_match_tap')
                : $this->allow(),
            self::SHIFT_END => $this->shiftEnd($timeOut, $capturedAt, $date),
            self::MANUAL_TIME => $timeOut > $capturedAt + $this->toleranceMs()
                ? $this->refuse('time_out_in_future')
                : $this->allow(),
            default => $this->refuse('unknown_time_out_type'),
        };
    }

    /** Epoch ms of the configured shift end on a date, in site time. */
    public function shiftEndMs(string $date): int
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $date.' '.config('attendance.shift_end', '16:00'),
            $this->timezone(),
        )->getTimestampMs();
    }

    private function shiftEnd(int $timeOut, int $capturedAt, string $date): array
    {
        $shiftEnd = $this->shiftEndMs($date);

        if ($timeOut !== $shiftEnd) {
            return $this->refuse('shift_end_time_not_shift_end');
        }

        if ($capturedAt < $shiftEnd) {
            return $this->refuse('shift_end_before_shift_end');
        }

        return $this->allow();
    }

    private function isWithinDay(int $epochMs, string $date): bool
    {
        $start = Carbon::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay();

        return $epochMs >= $start->getTimestampMs()
            && $epochMs < $start->copy()->addDay()->getTimestampMs();
    }

    private function toleranceMs(): int
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
