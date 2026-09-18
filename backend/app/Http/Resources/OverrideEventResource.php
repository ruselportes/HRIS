<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One override event for the Overrides & Audit review queue (Phase 7).
 *
 * "Hours at stake" is how much earlier than the real tap each worker was
 * credited — the time HR is actually being asked to pay for on the foreman's
 * word. Priced at the worker's own hourly rate so the queue can be sorted by
 * what an approval costs.
 *
 * @mixin AuditLog
 */
class OverrideEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timeOut = $this->action_type === AuditLog::MANUAL_TIME_OUT;
        $records = $this->reviewedAttendances()->map(fn (Attendance $a) => $timeOut ? self::timeOutRecord($a) : self::record($a));

        return [
            'audit_id' => $this->audit_id,
            'code' => $this->overrideCode(),
            'action_type' => $this->action_type,
            'review_status' => $this->review_status,
            'date' => $this->subject_date,
            // The moment the foreman applied it — the earliest real tap.
            'logged_at' => $this->timestamp,
            'description' => $this->description,
            'actor' => $this->person($this->actor),
            'crew' => $this->crew === null ? null : [
                'crew_id' => $this->crew->crew_id,
                'crew_name' => $this->crew->crew_name,
            ],
            'site' => $this->crew?->site === null ? null : [
                'site_id' => $this->crew->site->site_id,
                'site_name' => $this->crew->site->site_name,
            ],
            'record_count' => $records->count(),
            'hours_at_stake' => round($records->sum('credited_hours'), 2),
            'amount_at_stake' => round($records->sum('amount_at_stake'), 2),
            'reviewer' => $this->person($this->reviewer),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            // Omitted from the queue listing only; a single event (show, and the
            // approve/reject responses) carries its workers.
            'records' => $this->when(! $request->routeIs('overrides.index'), $records->values()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function record(Attendance $attendance): array
    {
        $creditedMinutes = 0;

        if ($attendance->time_in !== null && $attendance->captured_at !== null) {
            $creditedMinutes = max(0, (int) round(
                ($attendance->captured_at->getTimestamp() - $attendance->time_in->getTimestamp()) / 60
            ));
        }

        $hoursPerDay = (float) config('payroll.rates.regular_hours_per_day', 8);
        $dailyRate = (float) ($attendance->employee?->daily_rate ?? 0);
        $creditedHours = $creditedMinutes / 60;

        return [
            'attendance_id' => $attendance->attendance_id,
            'employee' => [
                'employee_id' => $attendance->employee?->employee_id,
                'employee_code' => $attendance->employee?->employee_code,
                'full_name' => $attendance->employee?->full_name,
                'trade_skill' => $attendance->employee?->trade_skill,
            ],
            'status' => $attendance->status,
            'credited_time_in' => $attendance->time_in,
            'tapped_at' => $attendance->captured_at,
            'credited_minutes' => $creditedMinutes,
            'credited_hours' => round($creditedHours, 2),
            'amount_at_stake' => $hoursPerDay > 0 ? round($creditedHours * $dailyRate / $hoursPerDay, 2) : 0.0,
        ];
    }

    /**
     * A manual time-out under review: the time the foreman stated, and when
     * they actually entered it. What it is worth depends on the day's hours
     * and any overtime, so it is shown as the gap, not priced.
     *
     * @return array<string, mixed>
     */
    public static function timeOutRecord(Attendance $attendance): array
    {
        $gapMinutes = 0;

        if ($attendance->time_out !== null && $attendance->time_out_captured_at !== null) {
            $gapMinutes = max(0, (int) round(
                ($attendance->time_out_captured_at->getTimestamp() - $attendance->time_out->getTimestamp()) / 60
            ));
        }

        return [
            'attendance_id' => $attendance->attendance_id,
            'employee' => [
                'employee_id' => $attendance->employee?->employee_id,
                'employee_code' => $attendance->employee?->employee_code,
                'full_name' => $attendance->employee?->full_name,
                'trade_skill' => $attendance->employee?->trade_skill,
            ],
            'status' => $attendance->status,
            'stated_time_out' => $attendance->time_out,
            'entered_at' => $attendance->time_out_captured_at,
            'gap_minutes' => $gapMinutes,
            'credited_minutes' => 0,
            'credited_hours' => 0.0,
            'amount_at_stake' => 0.0,
        ];
    }

    private function person($employee): ?array
    {
        return $employee === null ? null : [
            'employee_id' => $employee->employee_id,
            'full_name' => $employee->full_name,
        ];
    }
}
