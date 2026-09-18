<?php

namespace App\Services\Attendance;

use App\Models\Crew;
use App\Models\CrewAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may record roll call for a crew, and when (Phase 7 — UC-06, STD TC-05).
 *
 * Sync previously verified that an event came from an authentic, enrolled
 * device, but never that the device's foreman actually leads the crew named
 * in it. So after a crew was handed to another foreman, the original foreman's
 * phone could still submit accepted roll call for it — the opposite of what
 * TC-05 requires.
 *
 * The question is asked at the moment of capture, not at sync: roll call is
 * taken offline, so a tap the foreman made before a handover can reach the
 * server after it. That tap was legitimate and must still be accepted; a tap
 * made after the handover must not be. Leadership periods are kept as
 * crew_assignments rows so the answer survives the crew changing hands.
 *
 * The single place this is decided, so the roster endpoint and sync ingestion
 * cannot disagree — and the only place leadership changes, so the history
 * cannot drift from crews.foreman_id.
 */
class CrewLeadership
{
    /**
     * Whether the employee led the crew at the given instant, or right now
     * when no instant is given.
     */
    public function leads(int $employeeId, int $crewId, ?CarbonInterface $at = null): bool
    {
        $crew = Crew::query()->find($crewId);

        if ($crew === null) {
            return false;
        }

        // A crew whose foreman was never changed through here has no history,
        // so its current foreman is the only one it has had.
        if ($at === null || ! $this->periods($crewId)->exists()) {
            return (int) $crew->foreman_id === $employeeId;
        }

        return $this->periods($crewId)
            ->where('employee_id', $employeeId)
            ->where('started_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->exists();
    }

    /**
     * Who led the crew at an instant: the leadership period covering it, or the
     * current foreman for a crew whose leadership has never changed hands.
     */
    public function leaderAt(Crew $crew, CarbonInterface $at): ?int
    {
        $period = $this->periods($crew->crew_id)
            ->where('started_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->orderByDesc('started_at')
            ->first();

        return $period?->employee_id ?? $crew->foreman_id;
    }

    /**
     * End whoever leads the crew now and start the given employee, as of $at.
     * Ended rows are kept: they are the history.
     */
    public function handOver(Crew $crew, ?int $employeeId, string $type, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        DB::transaction(function () use ($crew, $employeeId, $type, $at) {
            $this->recordBaseline($crew);

            $this->periods($crew->crew_id)
                ->whereNull('ended_at')
                ->update(['status' => CrewAssignment::STATUS_ENDED, 'ended_at' => $at]);

            if ($employeeId !== null) {
                CrewAssignment::query()->create([
                    'crew_id' => $crew->crew_id,
                    'employee_id' => $employeeId,
                    'assignment_type' => $type,
                    'date_assigned' => $at->copy()->setTimezone(config('attendance.timezone', 'Asia/Manila'))->toDateString(),
                    'status' => 'active',
                    'started_at' => $at,
                ]);
            }

            $crew->update(['foreman_id' => $employeeId]);
        });
    }

    /**
     * A crew given its foreman before leadership was tracked (created with
     * foreman_id set) gets that foreman's period written down before it is
     * ended, so the history starts from the truth rather than from the change.
     */
    private function recordBaseline(Crew $crew): void
    {
        if ($crew->foreman_id === null || $this->periods($crew->crew_id)->exists()) {
            return;
        }

        CrewAssignment::query()->create([
            'crew_id' => $crew->crew_id,
            'employee_id' => $crew->foreman_id,
            'assignment_type' => CrewAssignment::TYPE_FOREMAN,
            'date_assigned' => $crew->deployed_at?->toDateString(),
            'status' => 'active',
            'started_at' => $crew->created_at ?? now(),
        ]);
    }

    private function periods(int $crewId): Builder
    {
        return CrewAssignment::query()
            ->where('crew_id', $crewId)
            ->whereIn('assignment_type', CrewAssignment::LEADERSHIP_TYPES);
    }
}
