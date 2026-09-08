<?php

namespace App\Policies;

use App\Models\Crew;
use App\Models\Employee;

/**
 * Crew builder authorization (UC-03), following the web nav access matrix:
 * engineers manage crews; HR and executives may view. Site Foremen get no
 * crew-builder access here — the Phase 4 mobile roll-call roster needs its own
 * scoped query/ability (own crew only), not this general policy.
 */
class CrewPolicy
{
    private function hasAnyRole(Employee $employee, array $slugs): bool
    {
        return in_array($employee->role?->slug, $slugs, true);
    }

    public function viewAny(Employee $employee): bool
    {
        return $this->hasAnyRole($employee, ['hr', 'engineer', 'executive']);
    }

    public function view(Employee $employee, Crew $crew): bool
    {
        return $this->viewAny($employee);
    }

    public function create(Employee $employee): bool
    {
        return $this->hasAnyRole($employee, ['engineer']);
    }

    public function update(Employee $employee, Crew $crew): bool
    {
        return $this->hasAnyRole($employee, ['engineer']);
    }

    public function manageMembers(Employee $employee, Crew $crew): bool
    {
        return $this->hasAnyRole($employee, ['engineer']);
    }

    public function designateForeman(Employee $employee, Crew $crew): bool
    {
        return $this->hasAnyRole($employee, ['engineer']);
    }

    public function deploy(Employee $employee, Crew $crew): bool
    {
        return $this->hasAnyRole($employee, ['engineer']);
    }
}
