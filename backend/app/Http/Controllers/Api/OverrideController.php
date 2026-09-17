<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OverrideEventResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Attendance\OverrideEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Overrides & Audit review queue (Phase 7 — UC-05, STD TC-04).
 *
 * Viewing follows the web nav matrix: HR, Site Engineers and Admins see every
 * override; a Site Foreman sees only their own. Deciding is HR's alone —
 * approval changes what workers are paid, so it sits with the role that owns
 * payroll, not with the engineer who supervises the foreman.
 */
class OverrideController extends Controller
{
    public function __construct(private OverrideEvents $overrideEvents) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'type' => ['nullable', Rule::in(AuditLog::OVERRIDE_TYPES)],
            'site_id' => ['nullable', 'integer'],
        ]);

        $events = $this->visibleTo($request->user())
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('review_status', $status))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('action_type', $type))
            ->when($filters['site_id'] ?? null, fn (Builder $q, $siteId) => $q->whereHas(
                'crew',
                fn (Builder $crew) => $crew->where('site_id', $siteId),
            ))
            ->orderByRaw("CASE review_status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('timestamp')
            ->get();

        $data = OverrideEventResource::collection($events)->resolve($request);
        $pending = collect($data)->where('review_status', AuditLog::REVIEW_PENDING);

        return response()->json([
            'data' => $data,
            'summary' => [
                'pending_events' => $pending->count(),
                'pending_records' => $pending->sum('record_count'),
                'hours_at_stake' => round($pending->sum('hours_at_stake'), 2),
                'amount_at_stake' => round($pending->sum('amount_at_stake'), 2),
            ],
        ]);
    }

    public function show(Request $request, int $override): JsonResponse
    {
        $event = $this->visibleTo($request->user())->findOrFail($override);

        return response()->json(['data' => new OverrideEventResource($event)]);
    }

    public function approve(Request $request, int $override): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        return $this->decide($request, $override, AuditLog::REVIEW_APPROVED, $note);
    }

    public function reject(Request $request, int $override): JsonResponse
    {
        // A rejection repays every worker under the event from their real tap,
        // so the reason is required: the foreman and the workers will ask.
        $note = $request->validate(['note' => ['required', 'string', 'max:1000']])['note'];

        return $this->decide($request, $override, AuditLog::REVIEW_REJECTED, $note);
    }

    private function decide(Request $request, int $override, string $decision, ?string $note): JsonResponse
    {
        $event = $this->visibleTo($request->user())->findOrFail($override);

        $event = $this->overrideEvents->decide($event, $request->user(), $decision, $note);

        $event->load(['actor', 'crew.site', 'reviewer', 'overriddenAttendances.employee']);

        return response()->json(['data' => new OverrideEventResource($event)]);
    }

    /**
     * Override events with records, scoped to what this person may see. Events
     * whose every worker was later re-tapped by hand have nothing left to
     * review, so they drop out rather than cluttering the queue.
     */
    private function visibleTo(Employee $viewer): Builder
    {
        return AuditLog::query()
            ->overrideEvents()
            ->whereHas('overriddenAttendances')
            ->with(['actor', 'crew.site', 'reviewer', 'overriddenAttendances.employee'])
            ->when(
                $viewer->role?->slug === 'foreman',
                fn (Builder $q) => $q->where('actor_id', $viewer->employee_id),
            );
    }
}
