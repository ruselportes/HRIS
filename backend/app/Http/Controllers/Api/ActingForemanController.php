<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignActingForemanRequest;
use App\Http\Resources\CrewResource;
use App\Models\Crew;
use App\Models\Employee;
use App\Services\ActingForemanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acting Foreman Reassignment (Phase 7 — UC-06, STD TC-05), backing
 * docs/prototypes/HRIS Acting Foreman Reassignment.dc.html. Site Engineer only:
 * it changes who may record a crew's attendance.
 */
class ActingForemanController extends Controller
{
    public function __construct(private readonly ActingForemanService $acting) {}

    /** GET /api/crews/{crew}/acting-candidates */
    public function candidates(Crew $crew): JsonResponse
    {
        $this->authorize('assignActingForeman', $crew);

        return response()->json(['candidates' => $this->acting->candidates($crew)]);
    }

    /** POST /api/crews/{crew}/acting-foreman — the single action TC-05 step 2 asks for. */
    public function store(AssignActingForemanRequest $request, Crew $crew): CrewResource
    {
        $this->authorize('assignActingForeman', $crew);

        $crew = $this->acting->assign(
            $crew,
            Employee::query()->with('role')->findOrFail($request->validated('foreman_id')),
            $request->validated('duration'),
            $request->user(),
        );

        return new CrewResource($crew->load('site', 'foreman', 'activeMembers.employee.role'));
    }

    /** DELETE /api/crews/{crew}/acting-foreman — end the cover early (Undo). */
    public function destroy(Request $request, Crew $crew): CrewResource
    {
        $this->authorize('assignActingForeman', $crew);

        $crew = $this->acting->end($crew, $request->user());

        return new CrewResource($crew->load('site', 'foreman', 'activeMembers.employee.role'));
    }
}
