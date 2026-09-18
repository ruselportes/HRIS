<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_payroll_detail (hour buckets, deductions) + Phase 8 itemised
 * payslip: basic and premium pay, each statutory deduction, employer shares,
 * the tax note, readiness and the day-by-day breakdown.
 */
class PayrollDetail extends Model
{
    public const READY = 'ready';

    public const BLOCKED = 'blocked';

    /** Paid on reconstructed attendance that was recovered and signed off (UC-07). */
    public const RECOVERED = 'recovered';

    protected $primaryKey = 'detail_id';

    protected $fillable = [
        'payroll_id',
        'regular_hours',
        'overtime_hours',
        'night_diff_hours',
        'rest_day_hours',
        'holiday_hours',
        'unworked_holiday_hours',
        'basic_pay',
        'premium_pay',
        'sss_employee',
        'philhealth_employee',
        'pagibig_employee',
        'withholding_tax',
        'other_deductions',
        'deductions',
        'sss_employer',
        'philhealth_employer',
        'pagibig_employer',
        'tax_note',
        'readiness',
        'blocked_reasons',
        'breakdown',
    ];

    protected function casts(): array
    {
        // decimal:2 so figures read the same on MySQL (strings) and SQLite
        // (floats) — a payslip must not depend on the database driver.
        $money = array_fill_keys([
            'regular_hours', 'overtime_hours', 'night_diff_hours', 'rest_day_hours', 'holiday_hours',
            'unworked_holiday_hours', 'basic_pay', 'premium_pay', 'sss_employee', 'philhealth_employee',
            'pagibig_employee', 'withholding_tax', 'other_deductions', 'deductions', 'sss_employer',
            'philhealth_employer', 'pagibig_employer',
        ], 'decimal:2');

        return $money + [
            'blocked_reasons' => 'array',
            'breakdown' => 'array',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'payroll_id', 'payroll_id');
    }
}
