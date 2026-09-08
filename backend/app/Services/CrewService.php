<?php

namespace App\Services;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Support\CertificationStatus;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Crew builder domain rules (UC-03): pool availability, foreman designation,
 * member assignment, and deployment. All methods assume the caller already
 * passed CrewPolicy authorization.
 */
class CrewService
{
    /**
     * Raw field workers eligible for the builder pool: worker/operator roles
     * (record-only, never login), not separated, and without an active
     * crew_assignment anywhere. Locale order: manpower board sorts by name.
     */
    public function pool(?array $excludeEmployeeIds = []): Collection
    {
        return Employee::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['worker', 'operator']))
            ->where('employment_status', '!=', 'separated')
            ->whereDoesntHave('crewAssignments', fn ($q) => $q->where('status', 'active'))
            ->when($excludeEmployeeIds, fn ($q) => $q->whereNotIn('employee_id', $excludeEmployeeIds))
            ->orderBy('last_name')
            ->with('role')
            ->get();
    }

    /**
     * Bulk-assign members to a crew. Validates every candidate is a field
     * worker (worker/operator), still employed, and not already active in a
     * different crew. Idempotent for candidates already in this crew.
     *
     * @return array<string,int> added and skipped counts
     */
    public function assignMembers(Crew $crew, array $employeeIds): array
    {
        $employees = Employee::query()
            ->whereIn('employee_id', $employeeIds)
            ->with('role', 'crewAssignments')
            ->get()
            ->keyBy('employee_id');

        $conflicts = [];

        foreach ($employeeIds as $id) {
            $employee = $employees[$id] ?? null;
            if ($employee === null) {
                continue;
            }

            $roleSlug = $employee->role?->slug;
            if (! in_array($roleSlug, ['worker', 'operator'], true)) {
                $conflicts[] = "{$employee->full_name} ({$employee->employee_code}) is not a field worker.";

                continue;
            }

            if ($employee->employment_status === 'separated') {
                $conflicts[] = "{$employee->full_name} ({$employee->employee_code}) is separated.";

                continue;
            }

            $activeElsewhere = $employee->crewAssignments
                ->where('status', 'active')
                ->first(fn ($a) => (int) $a->crew_id !== (int) $crew->crew_id);
            if ($activeElsewhere !== null) {
                $conflictName = Crew::query()->whereKey($activeElsewhere->crew_id)->value('crew_name') ?? 'another crew';
                $conflicts[] = "{$employee->full_name} ({$employee->employee_code}) is already active in {$conflictName}.";

                continue;
            }

            $existing = $employee->crewAssignments
                ->first(fn ($a) => (int) $a->crew_id === (int) $crew->crew_id && $a->status === 'active');

            if ($existing === null) {
                CrewAssignment::query()->updateOrCreate(
                    ['crew_id' => $crew->crew_id, 'employee_id' => $employee->employee_id],
                    ['status' => 'active', 'date_assigned' => null],
                );
                $added[] = $employee->employee_id;
            } else {
                $skipped[] = $employee->employee_id;
            }
        }

        if (! empty($conflicts)) {
            throw new HttpResponseException(
                new JsonResponse(['message' => implode(' ', $conflicts)], 422)
            );
        }

        return [
            'added' => $added ?? [],
            'skipped' => $skipped ?? [],
        ];
    }

    /**
     * Soft-remove a member: the active assignment row is flipped to 'inactive'
     * (keeps history; updateOrCreate re-activates if the worker is re-added).
     */
    public function removeMember(Crew $crew, Employee $employee): void
    {
        CrewAssignment::query()
            ->where('crew_id', $crew->crew_id)
            ->where('employee_id', $employee->employee_id)
            ->where('status', 'active')
            ->update(['status' => 'inactive', 'date_assigned' => null]);
    }

    /**
     * Designate a crew foreman. Phase 3 accepts a foreman who already leads
     * another crew (the acting-foreman/handoff flow is Phase 7's edge case).
     */
    public function designateForeman(Crew $crew, Employee $foreman): void
    {
        if ($foreman->role?->slug !== 'foreman') {
            throw new HttpResponseException(
                new JsonResponse(['message' => "{$foreman->full_name} does not hold the Site Foreman role."], 422)
            );
        }

        $crew->update(['foreman_id' => $foreman->employee_id]);
    }

    /**
     * Deploy a crew. Hard rule from the prototype: a crew with no foreman
     * cannot be deployed. On success flips crews.status -> 'deployed',
     * stamps crews.deployed_at, and dates every active member assignment.
     */
    public function deploy(Crew $crew, string $effectiveDate): void
    {
        if ($crew->foreman_id === null) {
            throw new HttpResponseException(
                new JsonResponse(['message' => 'Assign a foreman before deploying this crew.'], 422)
            );
        }

        $crew->update(['status' => 'deployed', 'deployed_at' => Carbon::now()]);

        CrewAssignment::query()
            ->where('crew_id', $crew->crew_id)
            ->where('status', 'active')
            ->update(['date_assigned' => $effectiveDate]);
    }

    /**
     * Section 02 "Site deployment today" — stat cards plus every crew grouped
     * by site, with the cert-status guardrail rolled up for member rows.
     */
    public function deploymentOverview(): array
    {
        $crews = Crew::query()
            ->with(['site', 'foreman', 'activeMembers.employee.role'])
            ->where('status', '!=', 'archived')
            ->orderBy('crew_name')
            ->get();

        $pool = $this->pool();

        $deployedWorkers = $crews
            ->where('status', 'deployed')
            ->sum(fn (Crew $crew) => $crew->activeMembers->count());

        $requiredWorkers = $crews->sum(fn (Crew $crew) => $crew->activeMembers->count());

        $crewsWithoutForeman = $crews->filter(fn (Crew $crew) => $crew->foreman_id === null);

        $sites = $crews->groupBy(fn (Crew $crew) => $crew->site_id)->map(function ($siteCrews) {
            $first = $siteCrews->first();

            return [
                'site' => [
                    'site_id' => $first->site->site_id,
                    'site_name' => $first->site->site_name,
                    'location' => $first->site->location,
                ],
                'workers' => $siteCrews->sum(fn (Crew $crew) => $crew->activeMembers->count()),
                'needs_foreman' => $siteCrews->contains(fn (Crew $crew) => $crew->foreman_id === null),
                'crews' => $siteCrews->map(fn (Crew $crew) => [
                    'crew_id' => $crew->crew_id,
                    'crew_name' => $crew->crew_name,
                    'status' => $crew->status,
                    'deployed_at' => $crew->deployed_at?->toIso8601String(),
                    'members_count' => $crew->activeMembers->count(),
                    'foreman' => $crew->foreman ? [
                        'employee_id' => $crew->foreman->employee_id,
                        'employee_code' => $crew->foreman->employee_code,
                        'full_name' => $crew->foreman->full_name,
                    ] : null,
                    'members' => $crew->activeMembers->map(fn ($a) => [
                        'employee_id' => $a->employee->employee_id,
                        'employee_code' => $a->employee->employee_code,
                        'full_name' => $a->employee->full_name,
                        'trade_skill' => $a->employee->trade_skill,
                        'cert_status' => CertificationStatus::for($a->employee->certification)['status'],
                    ])->values(),
                ])->values(),
            ];
        })->values();

        return [
            'deployed_workers' => $deployedWorkers,
            'required_workers' => $requiredWorkers,
            'crews_without_foreman' => $crewsWithoutForeman->count(),
            'pool_available' => $pool->count(),
            'sites' => $sites,
        ];
    }
}
