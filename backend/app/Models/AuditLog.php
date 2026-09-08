<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_audit_log (audit_id, actor_id → employee, action_type,
 * description, timestamp). actor_id is required: system-side events that have
 * no human actor are written to application logs, not this trail.
 */
class AuditLog extends Model
{
    protected $primaryKey = 'audit_id';

    protected $fillable = [
        'actor_id',
        'action_type',
        'description',
        'timestamp',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_id', 'employee_id');
    }
}
