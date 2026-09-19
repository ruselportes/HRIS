<?php

namespace App\Services\Leave;

use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Attendance\CrewLeadership;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which leave/overtime requests (Phase 9, UC-10), mirroring the
 * navigation access matrix:
 *
 *   HR / Executive — everything (Executive is read-only; the routes enforce it).
 *   Engineer — requests for their own site, plus their own filings.
 *   Foreman — requests for the members of the crew they lead now, plus their own.
 *   Admin — nothing: the navigation has no leave entry for Admin, so the data
 *   layer should not teach them one either.
 *
 * One place is responsible for the rule so the list and the show/get agree
 * and a user cannot peek at a number they found somewhere.
 */
class RequestReadScope
{
    public function __construct(private readonly CrewLeadership $leadership) {}

    public function leaves(Employee $actor): Builder
    {
        return $this->scope(LeaveRequest::query(), $actor);
    }

    public function overtimes(Employee $actor): Builder
    {
        return $this->scope(OvertimeRequest::query(), $actor);
    }

    public function visible(LeaveRequest|OvertimeRequest $request, Employee $actor): bool
    {
        $query = $request instanceof LeaveRequest ? LeaveRequest::query() : OvertimeRequest::query();

        return $this->scope($query, $actor)
            ->where($request->getKeyName(), $request->getKey())
            ->exists();
    }

    private function scope(Builder $query, Employee $actor): Builder
    {
        $slug = $actor->role?->slug;

        if (in_array($slug, ['hr', 'executive'], true)) {
            return $query;
        }

        $subjectId = (int) $actor->employee_id;

        if ($slug === 'engineer') {
            return $query->where(function (Builder $q) use ($actor, $subjectId) {
                $q->where('employee_id', $subjectId)->orWhere('filed_by', $subjectId);

                if ($actor->site_id !== null) {
                    $q->orWhereIn(
                        'employee_id',
                        Employee::query()->where('site_id', $actor->site_id)->select('employee_id'),
                    );
                }
            });
        }

        if ($slug === 'foreman') {
            $crewId = $this->leadership->crewLedBy($subjectId, Carbon::now());

            return $query->where(function (Builder $q) use ($subjectId, $crewId) {
                $q->where('employee_id', $subjectId)->orWhere('filed_by', $subjectId);

                if ($crewId !== null) {
                    $q->orWhereIn(
                        'employee_id',
                        CrewAssignment::query()
                            ->where('crew_id', $crewId)
                            ->where('assignment_type', CrewAssignment::TYPE_MEMBER)
                            ->where('status', 'active')
                            ->select('employee_id'),
                    );
                }
            });
        }

        return $query->whereRaw('0 = 1');
    }
}
