<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ERD: tbl_payroll (payroll_id, employee_id, pay_period_start, pay_period_end,
 * gross_pay, net_pay, status) + Phase 8 run_code. One row per employee per
 * pay period; a "run" is every row sharing a run_code. Stays Draft until
 * explicitly approved (TC-06).
 */
class Payroll extends Model
{
    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    protected $primaryKey = 'payroll_id';

    protected $fillable = [
        'employee_id',
        'run_code',
        'pay_period_start',
        'pay_period_end',
        'gross_pay',
        'net_pay',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_pay' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by', 'employee_id');
    }

    public function detail(): HasOne
    {
        return $this->hasOne(PayrollDetail::class, 'payroll_id', 'payroll_id');
    }
}
