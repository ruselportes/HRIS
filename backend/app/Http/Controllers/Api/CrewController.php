<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignMembersRequest;
use App\Http\Requests\DeployCrewRequest;
use App\Http\Requests\DesignateForemanRequest;
use App\Http\Requests\StoreCrewRequest;
use App\Http\Requests\UpdateCrewRequest;
use App\Http\Resources\CrewResource;
use App\Models\Crew;
use App\Models\Employee;
use App\Services\CrewService;
use App\Support\CertificationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CrewController extends Controller
{
    public function __construct(private readonly CrewService $crews) {}

    /**
     * GET /api/crews — crews (optionally by site), with roster + foreman.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Crew::class);

        $query = Crew::query()->with('site', 'foreman', 'activeMembers.employee.role');

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->integer('site_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return CrewResource::collection($query->orderBy('crew_name')->get());
    }

    /**
     * GET /api/crews/pool — unassigned field workers for the builder.
     */
    public function pool(): JsonResponse
    {
        $this->authorize('viewAny', Crew::class);

        $pool = $this->crews->pool()->map(function (Employee $employee) {
            $cert = CertificationStatus::for($employee->certification);

            return [
                'employee_id' => $employee->employee_id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'trade_skill' => $employee->trade_skill,
                'employment_status' => $employee->employment_status,
                'date_hired' => $employee->date_hired?->format('Y-m-d'),
                'cert_status' => $cert['status'],
                'cert_counts' => $cert,
                'blocked_by' => $cert['status'] === 'expired' ? 'expired_cert' : null,
            ];
        })->values();

        return response()->json([
            'pool' => $pool,
            'total' => $pool->count(),
        ]);
    }

    public function store(StoreCrewRequest $request): CrewResource
    {
        $this->authorize('create', Crew::class);

        $crew = Crew::create($request->safe()->merge(['status' => 'draft'])->all());

        return new CrewResource($crew->load('site', 'foreman', 'activeMembers.employee.role'));
    }

    public function show(Crew $crew): CrewResource
    {
        $this->authorize('view', $crew);

        return new CrewResource($crew->load('site', 'foreman', 'activeMembers.employee.role'));
    }

    public function update(UpdateCrewRequest $request, Crew $crew): CrewResource
    {
        $this->authorize('update', $crew);

        $crew->update($request->validated());

        return new CrewResource($crew->load('site', 'foreman', 'activeMembers.employee.role'));
    }

    /**
     * POST /api/crews/{crew}/members — bulk-add workers to the roster 'active'.
     */
    public function assignMembers(AssignMembersRequest $request, Crew $crew): CrewResource
    {
        $this->authorize('manageMembers', $crew);

        $this->crews->assignMembers($crew, $request->validated('employee_ids'));

        return new CrewResource($crew->fresh(['site', 'foreman', 'activeMembers.employee.role']));
    }

    /**
     * DELETE /api/crews/{crew}/members/{employee} — soft-remove from roster.
     */
    public function removeMember(Crew $crew, Employee $employee): CrewResource
    {
        $this->authorize('manageMembers', $crew);

        $this->crews->removeMember($crew, $employee);

        return new CrewResource($crew->fresh(['site', 'foreman', 'activeMembers.employee.role']));
    }

    /**
     * PUT /api/crews/{crew}/foreman — designate a Site Foreman.
     */
    public function foreman(DesignateForemanRequest $request, Crew $crew): CrewResource
    {
        $this->authorize('designateForeman', $crew);

        $this->crews->designateForeman($crew, Employee::findOrFail($request->validated('foreman_id')));

        return new CrewResource($crew->fresh(['site', 'foreman', 'activeMembers.employee.role']));
    }

    /**
     * POST /api/crews/{crew}/deploy — requires a foreman; flips status,
     * stamps deployed_at, and dates every active member assignment.
     */
    public function deploy(DeployCrewRequest $request, Crew $crew): CrewResource
    {
        $this->authorize('deploy', $crew);

        $this->crews->deploy($crew, $request->validated('effective_date') ?? now()->toDateString());

        return new CrewResource($crew->fresh(['site', 'foreman', 'activeMembers.employee.role']));
    }

    /**
     * GET /api/deployment — §02 "Site deployment today" overview.
     */
    public function deployment(): JsonResponse
    {
        $this->authorize('viewAny', Crew::class);

        return response()->json($this->crews->deploymentOverview());
    }
}
