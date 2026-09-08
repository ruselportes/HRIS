<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForemanCrewResource;
use App\Models\Crew;
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
    public function myCrew(Request $request): JsonResponse
    {
        $crew = Crew::query()
            ->with(['site', 'foreman', 'activeMembers.employee'])
            ->where('foreman_id', $request->user()->employee_id)
            ->where('status', 'deployed')
            ->orderByDesc('deployed_at')
            ->first();

        if ($crew === null) {
            return response()->json(['crew' => null]);
        }

        return response()->json(['crew' => new ForemanCrewResource($crew)]);
    }
}
