<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RollCallRequest;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\DeviceKey;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Roll Call monitor (C4, UC-04/FR-03) — a read-only "today" view over what
 * the phones sent. Capture itself stays mobile-only by design (records must
 * be signed on the device); this endpoint only reports per deployed crew
 * what arrived, what is missing, and what waits for review.
 *
 * Crew membership is always today's roster, even when the requested date is
 * in the past — the response says so, so a past date never misreads as a
 * historical roster.
 */
class RollCallMonitorController extends Controller
{
    /**
     * GET /api/rollcall/today?date= — one card per deployed crew in scope.
     *
     * Engineers see their own site only (a sent site filter would be a wider
     * surface than their role allows, so there is no site_id parameter at
     * all; no home site is a 403). Foremen see the deployed crews they lead
     * now. Everyone else is refused by the route middleware.
     */
    public function today(RollCallRequest $request): JsonResponse
    {
        $user = $request->user();
        $timezone = config('attendance.timezone', 'Asia/Manila');
        $today = Carbon::now($timezone)->startOfDay();
        $date = $request->query('date') !== null
            ? Carbon::parse($request->query('date'), $timezone)->startOfDay()
            : $today->copy();

        $crews = $this->scopedCrews($request);

        $cards = $crews->map(fn (Crew $crew) => $this->card($crew, $date))->values();

        return response()->json([
            'data' => $cards,
            'date' => $date->toDateString(),
            'roster_as_of' => $today->toDateString(),
            'roster_note' => $date->ne($today)
                ? 'Crew membership is the current roster, not the roster on the requested date.'
                : null,
        ]);
    }

    /** @return Collection<int, Crew> */
    private function scopedCrews(RollCallRequest $request): Collection
    {
        $user = $request->user();
        $query = Crew::query()
            ->with(['site', 'foreman'])
            ->where('status', 'deployed')
            ->orderBy('crew_name');

        if ($user->role?->slug === 'engineer') {
            abort_unless($user->site_id, 403, 'No home site is set for this engineer, so there is no roll call to show.');
            $query->where('site_id', $user->site_id);
        } else {
            $query->where('foreman_id', $user->employee_id);
        }

        return $query->get();
    }

    /** @return array<string, mixed> */
    private function card(Crew $crew, Carbon $date): array
    {
        $memberIds = CrewAssignment::query()
            ->where('crew_id', $crew->crew_id)
            ->where('assignment_type', CrewAssignment::TYPE_MEMBER)
            ->where('status', 'active')
            ->distinct()
            ->pluck('employee_id');

        $records = Attendance::query()
            ->where('crew_id', $crew->crew_id)
            ->where('date', $date->toDateString())
            ->get();

        $receivedIds = $records->pluck('employee_id')->unique()->values();
        $byStatus = $records->groupBy('status')->map->count();

        $pendingReviews = Attendance::query()
            ->where('crew_id', $crew->crew_id)
            ->where('date', $date->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereHas('overrideEvent', fn (Builder $qq) => $qq->where('review_status', AuditLog::REVIEW_PENDING))
                ->orWhereHas('timeOutEvent', fn (Builder $qq) => $qq->where('review_status', AuditLog::REVIEW_PENDING)))
            ->count();

        $lastSync = DeviceKey::query()
            ->where('employee_id', $crew->foreman_id)
            ->whereNull('revoked_at')
            ->max('last_synced_at');

        return [
            'crew_id' => $crew->crew_id,
            'crew_name' => $crew->crew_name,
            'site' => $crew->site?->site_name,
            'foreman' => $crew->foreman?->full_name,
            'crew_size' => $memberIds->count(),
            'received' => $byStatus->all(),
            'missing' => max(0, $memberIds->count() - $receivedIds->count()),
            'nothing_received' => $records->isEmpty(),
            'foreman_last_synced_at' => $lastSync,
            'pending_reviews' => $pendingReviews,
        ];
    }
}
