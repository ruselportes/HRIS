<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_leave_request (leave_id, employee_id → employee, approved_by
 * → employee, leave_type, date_from, date_to, status) + Phase 9 workflow
 * (UC-10): filed_by, reason, assigned_endorser_id, endorsed_by/endorsed_at,
 * approved_at, rejected_by/rejected_at/rejection_note.
 *
 * Statuses move pending → (endorsed) → approved, with rejected and cancelled
 * as terminals. Endorsement is a worker→foreman→HR hop: the assigned endorser
 * is decided at filing and can be re-assigned by HR, never by the requester.
 */
class LeaveRequest extends Model
{
    public const PENDING = 'pending';

    public const ENDORSED = 'endorsed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Leave types the filing form offers. 'sick' is the one retro leave may use. */
    public const TYPES = ['sick', 'vacation', 'personal', 'bereavement', 'leave_without_pay'];

    protected $primaryKey = 'leave_id';

    protected $fillable = [
        'employee_id',
        'filed_by',
        'leave_type',
        'reason',
        'date_from',
        'date_to',
        'status',
        'assigned_endorser_id',
        'endorsed_by',
        'endorsed_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_note',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'endorsed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** The employee the leave is for. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /** Who submitted it — usually the subject, but a foreman files for a worker. */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'filed_by', 'employee_id');
    }

    /** The endorser chosen at filing (or re-assigned by HR). */
    public function assignedEndorser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_endorser_id', 'employee_id');
    }

    public function endorser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'endorsed_by', 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by', 'employee_id');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by', 'employee_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::APPROVED, self::REJECTED, self::CANCELLED], true);
    }
}
