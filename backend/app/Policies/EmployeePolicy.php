<?php

namespace App\Policies;

use App\Models\Employee;

/**
 * Employee registry authorization (UC-02 Worker Registry), driven entirely by
 * Employee.role.slug — no separate users table (ERD).
 */
class EmployeePolicy
{
    private function hasAnyRole(Employee $employee, array $slugs): bool
    {
        return in_array($employee->role?->slug, $slugs, true);
    }

    /**
     * Who may browse the employee registry.
     * Prototype matrix: HR + Admin full; Engineer and Executive read-only.
     */
    public function viewAny(Employee $employee): bool
    {
        return $this->hasAnyRole($employee, ['hr', 'admin', 'engineer', 'executive']);
    }

    public function view(Employee $employee): bool
    {
        return $this->viewAny($employee);
    }

    public function create(Employee $employee): bool
    {
        return $this->hasAnyRole($employee, ['hr', 'admin']);
    }

    public function update(Employee $employee): bool
    {
        return $this->hasAnyRole($employee, ['hr', 'admin']);
    }

    public function delete(Employee $employee): bool
    {
        return false;
    }
}
