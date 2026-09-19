<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchApproveOvertimeRequest;
use App\Http\Requests\ReassignEndorserRequest;
use App\Http\Requests\RejectRequest;
use App\Http\Requests\StoreOvertimeRequest;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Services\Leave\RequestReadScope;
use App\Services\Leave\RequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Overtime Filing and Approval (Phase 9 — UC-10), mirroring LeaveRequest.
 * The same RequestReadScope/RequestWorkflowService decide visibility and the
 * endorse→approve path, so the two request kinds cannot drift apart.
 */
class OvertimeRequestController extends Controller
{
    public function __construct(
        private readonly RequestWorkflowService $workflow,
        private readonly RequestReadScope $readScope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->readScope->overtimes($request->user())
            ->with(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']);

        if (($status = $request->query('status')) !== null) {
            $query->where('status', $status);
        }

        if (($batchKey = $request->query('batch_key')) !== null) {
            $query->where('batch_key', $batchKey);
        }

        $data = $query->get()->sortByDesc('created_at')->map(fn (OvertimeRequest $r) => $this->row($r))->values();

        return response()->json(['data' => $data]);
    }

    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $ot = $this->workflow->fileOvertime($request->user(), $request->validated());

        return response()->json(['data' => $this->row($ot->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))], 201);
    }

    public function show(Request $request, OvertimeRequest $overtime): JsonResponse
    {
        $this->assertVisible($request->user(), $overtime);

        return response()->json(['data' => $this->row($overtime->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function endorse(Request $request, OvertimeRequest $overtime): JsonResponse
    {
        $this->workflow->endorse($overtime, $request->user());

        return response()->json(['data' => $this->row($overtime->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function batchApprove(BatchApproveOvertimeRequest $request): JsonResponse
    {
        $result = $this->workflow->approveBatch($request->validated('ot_ids'), $request->user());

        return response()->json([
            'data' => [
                'approved' => $result['approved'],
                'skipped' => $result['skipped'],
            ],
        ]);
    }

    public function approve(Request $request, OvertimeRequest $overtime): JsonResponse
    {
        $this->workflow->approve($overtime, $request->user());

        return response()->json(['data' => $this->row($overtime->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function reject(RejectRequest $request, OvertimeRequest $overtime): JsonResponse
    {
        $this->workflow->reject($overtime, $request->user(), $request->validated('rejection_note'));

        return response()->json(['data' => $this->row($overtime->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function cancel(Request $request, OvertimeRequest $overtime): JsonResponse
    {
        $this->workflow->cancel($overtime, $request->user());

        return response()->json(['data' => $this->row($overtime->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function reassignEndorser(ReassignEndorserRequest $request, OvertimeRequest $overtime): JsonResponse
    {
        $this->workflow->reassignEndorser($overtime, $request->user(), (int) $request->validated('employee_id'));

        return response()->json(['data' => $this->row($overtime->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    private function assertVisible(Employee $actor, OvertimeRequest $overtime): void
    {
        if (! $this->readScope->visible($overtime, $actor)) {
            abort(404, 'Overtime request not found.');
        }
    }

    private function row(OvertimeRequest $r): array
    {
        return [
            'ot_id' => $r->ot_id,
            'status' => $r->status,
            'ot_date' => $r->ot_date?->toDateString(),
            'start_time' => $r->start_time,
            'end_time' => $r->end_time,
            'hours_requested' => $r->hours_requested === null ? null : (float) $r->hours_requested,
            'reason' => $r->reason,
            'batch_key' => $r->batch_key,
            'created_at' => $r->created_at?->toIso8601String(),
            'employee' => $this->person($r->employee),
            'filed_by' => $this->person($r->filer),
            'assigned_endorser' => $this->person($r->assignedEndorser),
            'endorsed_by' => $this->person($r->endorser),
            'endorsed_at' => $r->endorsed_at?->toIso8601String(),
            'approved_by' => $this->person($r->approver),
            'approved_at' => $r->approved_at?->toIso8601String(),
            'rejected_by' => $this->person($r->rejecter),
            'rejected_at' => $r->rejected_at?->toIso8601String(),
            'rejection_note' => $r->rejection_note,
        ];
    }

    private function person(?Employee $e): ?array
    {
        return $e === null ? null : [
            'employee_id' => $e->employee_id,
            'employee_code' => $e->employee_code,
            'full_name' => $e->full_name,
            'role' => $e->role?->role_name,
            'site' => $e->site?->site_name,
        ];
    }
}
