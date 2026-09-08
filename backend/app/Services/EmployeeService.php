<?php

namespace App\Services;

use App\Models\Employee;

/**
 * Employee registry domain logic — keeps controllers thin and gives the
 * auto-numbering / separation rules a single, unit-testable home.
 */
class EmployeeService
{
    private const CODE_PREFIX = 'ADC-';

    /**
     * Next sequential employee code (ADC-0001, ADC-0002, …). "Next available —
     * editable" per the add-employee prototype.
     */
    public function nextEmployeeCode(): string
    {
        $max = Employee::query()
            ->where('employee_code', 'like', self::CODE_PREFIX.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) as max_seq')
            ->value('max_seq');

        return self::CODE_PREFIX.str_pad((string) (((int) $max) + 1), 4, '0', STR_PAD_LEFT);
    }
}
