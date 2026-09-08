<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Roster payload the mobile app caches locally for offline attendance
 * capture. Kept intentionally small — just what the tap checklist needs.
 */
class ForemanCrewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'crew_id' => $this->crew_id,
            'crew_name' => $this->crew_name,
            'status' => $this->status,
            'deployed_at' => $this->deployed_at,
            'site' => [
                'site_id' => $this->site->site_id,
                'site_name' => $this->site->site_name,
                'location' => $this->site->location,
            ],
            'members' => $this->activeMembers->map(fn ($assignment) => [
                'employee_id' => $assignment->employee->employee_id,
                'employee_code' => $assignment->employee->employee_code,
                'first_name' => $assignment->employee->first_name,
                'last_name' => $assignment->employee->last_name,
                'trade_skill' => $assignment->employee->trade_skill,
            ])->values(),
        ];
    }
}
