<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_overtime_request (ot_id, employee_id, approved_by, ot_date,
 * hours_requested, status) + Phase 8 start_time/end_time — the window
 * overtime and night differential are paid from. Filing and approval are
 * Phase 9 (UC-10); the payroll engine reads approved ones.
 */
class OvertimeRequest extends Model
{
    public const APPROVED = 'approved';

    protected $primaryKey = 'ot_id';

    protected $fillable = [
        'employee_id',
        'approved_by',
        'ot_date',
        'start_time',
        'end_time',
        'hours_requested',
        'status',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
