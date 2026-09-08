<?php

namespace App\Http\Resources;

use App\Models\Crew;
use App\Support\CertificationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Crew $resource
 */
class CrewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $crew = $this->resource;

        return [
            'crew_id' => $crew->crew_id,
            'crew_name' => $crew->crew_name,
            'site_id' => $crew->site_id,
            'status' => $crew->status,
            'deployed_at' => $crew->deployed_at?->toIso8601String(),
            'site' => $this->whenLoaded('site', fn () => [
                'site_id' => $crew->site->site_id,
                'site_name' => $crew->site->site_name,
                'location' => $crew->site->location,
            ]),
            'foreman' => $this->whenLoaded('foreman', fn () => $crew->foreman ? [
                'employee_id' => $crew->foreman->employee_id,
                'employee_code' => $crew->foreman->employee_code,
                'full_name' => $crew->foreman->full_name,
                'trade_skill' => $crew->foreman->trade_skill,
            ] : null),
            'members_count' => $this->whenLoaded('activeMembers', fn () => $crew->activeMembers->count()),
            'without_foreman' => $crew->foreman_id === null,
            'members' => $this->whenLoaded('activeMembers', fn () => $crew->activeMembers->map(function ($assignment) {
                $cert = CertificationStatus::for($assignment->employee->certification);

                return [
                    'employee_id' => $assignment->employee->employee_id,
                    'employee_code' => $assignment->employee->employee_code,
                    'full_name' => $assignment->employee->full_name,
                    'trade_skill' => $assignment->employee->trade_skill,
                    'employment_status' => $assignment->employee->employment_status,
                    'date_assigned' => $assignment->date_assigned?->format('Y-m-d'),
                    'cert_status' => $cert['status'],
                    'cert_counts' => $cert,
                ];
            })->values()),
        ];
    }
}
