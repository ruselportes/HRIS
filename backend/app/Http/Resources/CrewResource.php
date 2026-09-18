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
            // Phase 7 (UC-06): during a cover, `foreman` is the acting foreman.
            'acting' => self::actingCover($crew),
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

    /**
     * The crew's acting foreman cover, or null — shared by every payload that
     * names a crew's foreman, so none of them can present an acting foreman as
     * the regular one.
     *
     * @return array{until:string|null, acting_foreman:array|null, regular_foreman:array|null}|null
     */
    public static function actingCover(Crew $crew): ?array
    {
        if (! $crew->hasActingForeman()) {
            return null;
        }

        $person = fn ($employee) => $employee === null ? null : [
            'employee_id' => $employee->employee_id,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
        ];

        return [
            'until' => $crew->acting_until?->toIso8601String(),
            'acting_foreman' => $person($crew->foreman),
            'regular_foreman' => $person($crew->regularForeman),
        ];
    }
}
