<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForemanCrewResource;
use App\Models\Crew;
use App\Services\Attendance\TimeInPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-facing, foreman-scoped endpoints (UC-04). Deliberately separate from
 * CrewController/CrewPolicy — those are engineer-managed crew *administration*
 * (see CrewPolicy docblock); this is a foreman reading their own roster to
 * cache locally for offline attendance capture. Route is role:foreman-gated.
 */
class ForemanController extends Controller
{
    public function myCrew(Request $request, TimeInPolicy $timeInPolicy): JsonResponse
    {
        $crew = Crew::query()
            ->with(['site', 'foreman', 'activeMembers.employee'])
            ->where('foreman_id', $request->user()->employee_id)
            ->where('status', 'deployed')
            ->orderByDesc('deployed_at')
            ->first();

        return response()->json([
            'crew' => $crew === null ? null : new ForemanCrewResource($crew),
            // Cached with the roster (Phase 7): the late-start override is
            // offered offline, so the phone needs the shift rules before it
            // loses signal, not when it next reaches the server.
            'shift' => $timeInPolicy->shiftConfig(),
        ]);
    }
}
