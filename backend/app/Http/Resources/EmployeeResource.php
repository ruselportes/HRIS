<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'email' => $this->email,
            'role_id' => $this->role_id,
            'site_id' => $this->site_id,
            'role' => $this->whenLoaded('role', fn () => [
                'role_id' => $this->role->role_id,
                'role_name' => $this->role->role_name,
                'slug' => $this->role->slug,
            ]),
            'site' => $this->whenLoaded('site', fn () => [
                'site_id' => $this->site->site_id,
                'site_name' => $this->site->site_name,
                'location' => $this->site->location,
            ]),
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'trade_skill' => $this->trade_skill,
            'daily_rate' => $this->daily_rate,
            'certification' => $this->certification,
            'emergency_contact' => $this->emergency_contact,
            'employment_status' => $this->employment_status,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'mobile' => $this->mobile,
            'civil_status' => $this->civil_status,
            'dependents' => $this->dependents,
            'address' => $this->address,
            'blood_type' => $this->blood_type,
            'tin' => $this->tin,
            'sss' => $this->sss,
            'philhealth' => $this->philhealth,
            'pag_ibig' => $this->pag_ibig,
            'date_hired' => $this->date_hired?->format('Y-m-d'),
            'cost_centre' => $this->cost_centre,
            'crew_assignments' => $this->whenLoaded('crewAssignments'),
        ];
    }
}
