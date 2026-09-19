<?php

namespace App\Services\Leave;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Attendance\CrewLeadership;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Leave & Overtime Filing and Approval (Phase 9 — UC-10).
 *
 * The two-hop model: the request is assigned an endorser at filing (the crew
 * leader, else a Site Engineer on the subject's site), the endorser endorses
 * it, and HR approves. An approved request is final — there is no revoke, by
 * design; a filer who changes their mind cancels only while it is still pending.
 *
 * Conflicts are its terms of employment:
 *   - Overtime is refused on any date an approved leave covers.
 *   - Leave is refused on any forward date with approved overtime.
 *   - Retrospective leave may only be sick leave, and refuses a day the worker
 *     was clocked present or late (or it would rewrite a day already booked).
 */
class RequestWorkflowService
{
    public function __construct(private readonly CrewLeadership $leadership) {}

    /**
     * File a leave request. The subject and actor may differ — a foreman files
     * for a worker on their crew. Resolves and stores the endorser up front, so
     * acting-cover changes after filing never move the request under the rug:
     * the endorser is whoever led when it was filed, exactly what a printed
     * workflow would look like.
     */
    public function fileLeave(Employee $actor, array $data): LeaveRequest
    {
        $subject = Employee::query()->findOrFail((int) $data['employee_id']);
        $this->assertCanFileFor($actor, $subject);
        $this->assertEligible($subject);

        $from = (string) $data['date_from'];
        $to = (string) $data['date_to'];
        $dates = CarbonPeriod::create($from, $to);

        if ($dates === false || ! $dates->valid()) {
            throw $this->unprocessable('date_to must not be before date_from.');
        }

        foreach ($dates as $date) {
            $day = $date->format('Y-m-d');

            if ($day >= $this->today()) {
                $hasOvertime = OvertimeRequest::query()
                    ->where('employee_id', $subject->employee_id)
                    ->where('status', OvertimeRequest::APPROVED)
                    ->whereDate('ot_date', $day)
                    ->exists();

                if ($hasOvertime) {
                    throw $this->unprocessable(
                        "{$subject->full_name} already has approved overtime on {$day}; a leave cannot cover it."
                    );
                }
            } else {
                // A day already past: only sick leave may go back, and never
                // onto a day with a present or late roll call.
                if (($data['leave_type'] ?? null) !== 'sick') {
                    throw $this->unprocessable(
                        "Retrospective leave (before today) may only be sick leave; {$day} is in the past."
                    );
                }

                $clockedIn = Attendance::query()
                    ->where('employee_id', $subject->employee_id)
                    ->where('date', $day)
                    ->whereIn('status', ['present', 'late'])
                    ->exists();

                if ($clockedIn) {
                    throw $this->unprocessable(
                        "{$subject->full_name} was clocked in on {$day}; a retrospective leave cannot rewrite it."
                    );
                }
            }
        }

        return DB::transaction(function () use ($actor, $subject, $data, $from, $to) {
            $endorserId = $this->resolveEndorser($subject);

            $request = LeaveRequest::query()->create([
                'employee_id' => $subject->employee_id,
                'filed_by' => $actor->employee_id,
                'leave_type' => $data['leave_type'],
                'reason' => $data['reason'] ?? null,
                'date_from' => $from,
                'date_to' => $to,
                'status' => LeaveRequest::PENDING,
                'assigned_endorser_id' => $endorserId,
            ]);

            $this->audit($actor, AuditLog::REQUEST_SUBMITTED,
                "Leave request #{$request->leave_id} filed for {$subject->full_name} "
                ."{$request->leave_type} {$from}–{$to}.".($endorserId !== null ? " Endorser: {$this->nameOf($endorserId)}." : ' No endorser on site; HR reviews directly.'));

            return $request;
        });
    }

    /** File an overtime request for one night (or one batch_key group of nights). */
    public function fileOvertime(Employee $actor, array $data): OvertimeRequest
    {
        $subject = Employee::query()->findOrFail((int) $data['employee_id']);
        $this->assertCanFileFor($actor, $subject);
        $this->assertEligible($subject);

        $day = (string) $data['ot_date'];

        if ($day < $this->today()) {
            throw $this->unprocessable("Overtime cannot be filed for a past date; {$day} is already gone.");
        }

        $coveredByLeave = LeaveRequest::query()
            ->where('employee_id', $subject->employee_id)
            ->where('status', LeaveRequest::APPROVED)
            ->where('date_from', '<=', $day)
            ->where('date_to', '>=', $day)
            ->exists();

        if ($coveredByLeave) {
            throw $this->unprocessable("{$day} is covered by an approved leave; overtime cannot be filed on it.");
        }

        return DB::transaction(function () use ($actor, $subject, $data, $day) {
            $endorserId = $this->resolveEndorser($subject);

            $request = OvertimeRequest::query()->create([
                'employee_id' => $subject->employee_id,
                'filed_by' => $actor->employee_id,
                'ot_date' => $day,
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'hours_requested' => $data['hours_requested'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => OvertimeRequest::PENDING,
                'batch_key' => $data['batch_key'] ?? null,
                'assigned_endorser_id' => $endorserId,
            ]);

            $this->audit($actor, AuditLog::REQUEST_SUBMITTED,
                "Overtime request #{$request->ot_id} filed for {$subject->full_name} on {$day} "
                .($request->start_time ? "({$request->start_time}–{$request->end_time})" : '').'.'
                .($endorserId !== null ? " Endorser: {$this->nameOf($endorserId)}." : ' No endorser on site; HR reviews directly.'));

            return $request;
        });
    }

    /** The assigned endorser endorses a pending request — no one else may. */
    public function endorse(LeaveRequest|OvertimeRequest $request, Employee $actor): void
    {
        if ($request->status !== ($request instanceof LeaveRequest ? LeaveRequest::PENDING : OvertimeRequest::PENDING)) {
            throw $this->unprocessable($this->label($request).' is not pending; only a pending request can be endorsed.');
        }

        if ((int) $request->assigned_endorser_id !== (int) $actor->employee_id) {
            abort(403, 'This request is not assigned to you for endorsement.');
        }

        $this->assertNotParty($request, $actor, 'endorse', allowFiler: true);

        DB::transaction(function () use ($request, $actor) {
            $request->update([
                'status' => $request instanceof LeaveRequest ? LeaveRequest::ENDORSED : OvertimeRequest::ENDORSED,
                'endorsed_by' => $actor->employee_id,
                'endorsed_at' => Carbon::now(),
            ]);

            $this->audit($actor, AuditLog::REQUEST_ENDORSED,
                "{$this->label($request)} endorsed by {$actor->full_name}.");
        });
    }

    /**
     * HR approves the request. A request with an assigned endorser must be
     * endorsed first; one filed with nobody to endorse is approved directly
     * from pending. The approver cannot be the subject, filer, or endorser —
     * a fresh pair of eyes each time.
     *
     * Approved is final: reopening a past pay period happens in payroll, not
     * by un-approving a request.
     */
    public function approve(LeaveRequest|OvertimeRequest $request, Employee $actor): void
    {
        $pending = $request instanceof LeaveRequest ? LeaveRequest::PENDING : OvertimeRequest::PENDING;
        $endorsed = $request instanceof LeaveRequest ? LeaveRequest::ENDORSED : OvertimeRequest::ENDORSED;

        if ($request->status === $endorsed) {
            // Normal path: the assigned endorser has seen it.
        } elseif ($request->status === $pending && $request->assigned_endorser_id === null) {
            // HR-direct: nobody on site was eligible, so HR reviews from the start.
        } elseif ($request->status === $pending) {
            throw $this->unprocessable(
                $this->label($request).' is still with its endorser; it must be endorsed first.'
            );
        } else {
            throw $this->unprocessable($this->label($request).' is already decided; nothing left to approve.');
        }

        $this->assertNotParty($request, $actor, 'approve');

        DB::transaction(function () use ($request, $actor) {
            $request->update([
                'status' => $request instanceof LeaveRequest ? LeaveRequest::APPROVED : OvertimeRequest::APPROVED,
                'approved_by' => $actor->employee_id,
                'approved_at' => Carbon::now(),
            ]);

            $this->audit($actor, AuditLog::REQUEST_APPROVED,
                "{$this->label($request)} approved by {$actor->full_name}.");
        });
    }

    /** HR rejects a pending or endorsed request, with a note. */
    public function reject(LeaveRequest|OvertimeRequest $request, Employee $actor, string $note): void
    {
        $pending = $request instanceof LeaveRequest ? LeaveRequest::PENDING : OvertimeRequest::PENDING;
        $endorsed = $request instanceof LeaveRequest ? LeaveRequest::ENDORSED : OvertimeRequest::ENDORSED;

        if (! in_array($request->status, [$pending, $endorsed], true)) {
            throw $this->unprocessable($this->label($request).' is already decided; nothing left to reject.');
        }

        if (trim($note) === '') {
            throw $this->unprocessable('A rejection needs a note the foreman can act on.');
        }

        DB::transaction(function () use ($request, $actor, $note) {
            $request->update([
                'status' => $request instanceof LeaveRequest ? LeaveRequest::REJECTED : OvertimeRequest::REJECTED,
                'rejected_by' => $actor->employee_id,
                'rejected_at' => Carbon::now(),
                'rejection_note' => $note,
            ]);

            $this->audit($actor, AuditLog::REQUEST_REJECTED,
                "{$this->label($request)} rejected by {$actor->full_name}: {$note}");
        });
    }

    /** Only the filer, and only while it is pending. Approved is final. */
    public function cancel(LeaveRequest|OvertimeRequest $request, Employee $actor): void
    {
        if ((int) $request->filed_by !== (int) $actor->employee_id) {
            abort(403, 'Only the employee who filed this request can cancel it.');
        }

        if ($request->status !== ($request instanceof LeaveRequest ? LeaveRequest::PENDING : OvertimeRequest::PENDING)) {
            throw $this->unprocessable('Only a pending request can be cancelled.');
        }

        DB::transaction(function () use ($request, $actor) {
            $request->update([
                'status' => $request instanceof LeaveRequest ? LeaveRequest::CANCELLED : OvertimeRequest::CANCELLED,
            ]);

            $this->audit($actor, AuditLog::REQUEST_CANCELLED,
                "{$this->label($request)} cancelled by {$actor->full_name}.");
        });
    }

    /**
     * HR re-assigns a pending request's endorser — the one audited change to
     * who sees a request before it is approved. Only while pending: an
     * endorsed request has already been seen; moving it after would be
     * editing the record. Eligibility is re-checked here, not trusted from
     * the caller.
     */
    public function reassignEndorser(LeaveRequest|OvertimeRequest $request, Employee $actor, int $newEndorserId): void
    {
        if ($request->status !== ($request instanceof LeaveRequest ? LeaveRequest::PENDING : OvertimeRequest::PENDING)) {
            throw $this->unprocessable("Only a pending request can change endorsers; {$this->label($request)} is already {$request->status}.");
        }

        $newEndorser = Employee::query()->with('role')->find($newEndorserId);

        if ($newEndorser === null) {
            throw $this->unprocessable('The new endorser does not exist.');
        }

        if ((int) $newEndorser->employee_id === (int) $request->employee_id
            || (int) $newEndorser->employee_id === (int) $request->filed_by) {
            throw $this->unprocessable('The endorser cannot be the subject or the filer of the request.');
        }

        $this->assertEligible($newEndorser);

        if (! $newEndorser->canSignIn()) {
            throw $this->unprocessable('The new endorser must have an HRIS sign-in account.');
        }

        DB::transaction(function () use ($request, $actor, $newEndorser) {
            $old = $this->nameOf((int) $request->assigned_endorser_id);

            $request->update(['assigned_endorser_id' => $newEndorser->employee_id]);

            $this->audit($actor, AuditLog::REQUEST_ENDORSER_REASSIGNED,
                "{$this->label($request)} endorser changed by {$actor->full_name}"
                .($old !== null ? " from {$old}" : '')." to {$newEndorser->full_name}.");
        });
    }

    /**
     * Who should endorse a request for this subject: the leader of their
     * deployed crew (who sees the attendance on the ground), else a Site
     * Engineer assigned to their site, choosing the lowest employee_id among
     * equally-qualified people so the decision is stable and re-computable.
     * The subject can never endorse their own request.
     */
    public function resolveEndorser(Employee $subject): ?int
    {
        $crewIds = CrewAssignment::query()
            ->where('employee_id', $subject->employee_id)
            ->where('assignment_type', CrewAssignment::TYPE_MEMBER)
            ->whereHas('crew', fn ($q) => $q->where('status', 'deployed'))
            ->pluck('crew_id');

        $candidates = collect();

        foreach (Crew::query()->whereIn('crew_id', $crewIds)->get() as $crew) {
            $leader = $this->leadership->leaderAt($crew, Carbon::now());

            if ($leader !== null) {
                $candidates->push((int) $leader);
            }
        }

        if ($candidates->isEmpty() && $subject->site_id !== null) {
            $candidates = Employee::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'engineer'))
                ->where('site_id', $subject->site_id)
                ->where('employment_status', '!=', 'separated')
                ->pluck('employee_id')
                ->map(fn ($id) => (int) $id);
        }

        $eligible = $candidates
            ->reject(fn (int $id) => $id === (int) $subject->employee_id)
            ->unique()
            ->values();

        return $eligible->isEmpty() ? null : $eligible->min();
    }

    /** The filer may only file for themselves, their crew (foreman), their site (engineer), or anyone (HR). */
    private function assertCanFileFor(Employee $actor, Employee $subject): void
    {
        if ((int) $actor->employee_id === (int) $subject->employee_id) {
            return;
        }

        $slug = $actor->role?->slug;

        if ($slug === 'hr') {
            return;
        }

        if ($slug === 'engineer' && (int) $actor->site_id === (int) $subject->site_id) {
            return;
        }

        if ($slug === 'foreman') {
            $crewId = $this->leadership->crewLedBy((int) $actor->employee_id, Carbon::now());

            $member = $crewId !== null && CrewAssignment::query()
                ->where('crew_id', $crewId)
                ->where('employee_id', $subject->employee_id)
                ->where('assignment_type', CrewAssignment::TYPE_MEMBER)
                ->where('status', 'active')
                ->exists();

            if ($member) {
                return;
            }
        }

        abort(403, 'You may only file a request for yourself or your own crew.');
    }

    private function assertEligible(Employee $employee): void
    {
        if ($employee->employment_status === 'separated') {
            throw $this->unprocessable("{$employee->full_name} is separated and cannot hold requests.");
        }
    }

    /** Nobody involved in the request decides on it. For an endorsement the
     *  filer is normally the one endorsing (a foreman files and endorses for
     *  his crew), so the filer is exempt from that check; the subject can
     *  never decide on their own request in any role. */
    private function assertNotParty(LeaveRequest|OvertimeRequest $request, Employee $actor, string $action, bool $allowFiler = false): void
    {
        $parties = [(int) $request->employee_id];

        if (! $allowFiler) {
            // Approval/rejection: a fresh pair of eyes each time.
            $parties[] = (int) $request->filed_by;

            if ($request->endorsed_by !== null) {
                $parties[] = (int) $request->endorsed_by;
            }
        }

        $clean = ! in_array((int) $actor->employee_id, $parties, true);

        if (! $clean) {
            $role = $action === 'endorse' ? 'endorser' : ($action === 'reject' ? 'rejecter' : 'approver');
            throw $this->unprocessable(
                "The {$role} of a request cannot be its subject, filer, or endorser."
            );
        }
    }

    private function audit(Employee $actor, string $action, string $description): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->employee_id,
            'action_type' => $action,
            'timestamp' => Carbon::now(),
            'description' => $description,
        ]);
    }

    private function label(LeaveRequest|OvertimeRequest $request): string
    {
        return $request instanceof LeaveRequest
            ? "Leave request #{$request->leave_id}"
            : "Overtime request #{$request->ot_id}";
    }

    private function nameOf(?int $employeeId): ?string
    {
        return $employeeId === null ? null : (Employee::query()->find($employeeId)?->full_name);
    }

    private function today(): string
    {
        return Carbon::now(config('attendance.timezone', 'Asia/Manila'))->toDateString();
    }

    private function unprocessable(string $message): HttpResponseException
    {
        return new HttpResponseException(new JsonResponse(['message' => $message], 422));
    }
}
