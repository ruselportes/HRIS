<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReassignEndorserRequest;
use App\Http\Requests\RejectRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Leave\RequestReadScope;
use App\Services\Leave\RequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leave Filing and Approval (Phase 9 — UC-10). Routes are read-scoped by
 * RequestReadScope, and the approval actions carry their own role gates:
 * endorse picks the assigned endorser, approve/reject/reassign are HR-only,
 * cancel is filer-only.
 */
class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly RequestWorkflowService $workflow,
        private readonly RequestReadScope $readScope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->readScope->leaves($request->user())
            ->with(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']);

        if (($status = $request->query('status')) !== null) {
            $query->where('status', $status);
        }

        $data = $query->get()->sortByDesc('created_at')->map(fn (LeaveRequest $r) => $this->row($r))->values();

        return response()->json(['data' => $data]);
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $leave = $this->workflow->fileLeave($request->user(), $request->validated());

        return response()->json(['data' => $this->row($leave->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))], 201);
    }

    public function show(Request $request, LeaveRequest $leave): JsonResponse
    {
        $this->assertVisible($request->user(), $leave);

        return response()->json(['data' => $this->row($leave->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function endorse(Request $request, LeaveRequest $leave): JsonResponse
    {
        $this->workflow->endorse($leave, $request->user());

        return response()->json(['data' => $this->row($leave->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function approve(Request $request, LeaveRequest $leave): JsonResponse
    {
        $this->workflow->approve($leave, $request->user());

        return response()->json(['data' => $this->row($leave->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function reject(RejectRequest $request, LeaveRequest $leave): JsonResponse
    {
        $this->workflow->reject($leave, $request->user(), $request->validated('rejection_note'));

        return response()->json(['data' => $this->row($leave->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function cancel(Request $request, LeaveRequest $leave): JsonResponse
    {
        $this->workflow->cancel($leave, $request->user());

        return response()->json(['data' => $this->row($leave->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    public function reassignEndorser(ReassignEndorserRequest $request, LeaveRequest $leave): JsonResponse
    {
        $this->workflow->reassignEndorser($leave, $request->user(), (int) $request->validated('employee_id'));

        return response()->json(['data' => $this->row($leave->fresh()->load(['employee.role', 'employee.site', 'filer', 'assignedEndorser', 'endorser', 'approver', 'rejecter']))]);
    }

    private function assertVisible(Employee $actor, LeaveRequest $leave): void
    {
        if (! $this->readScope->visible($leave, $actor)) {
            abort(404, 'Leave request not found.');
        }
    }

    private function row(LeaveRequest $r): array
    {
        return [
            'leave_id' => $r->leave_id,
            'status' => $r->status,
            'leave_type' => $r->leave_type,
            'date_from' => $r->date_from?->toDateString(),
            'date_to' => $r->date_to?->toDateString(),
            'reason' => $r->reason,
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
