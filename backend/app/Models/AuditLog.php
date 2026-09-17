<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD: tbl_audit_log (audit_id, actor_id → employee, action_type,
 * description, timestamp). actor_id is required: system-side events that have
 * no human actor are written to application logs, not this trail.
 *
 * Phase 7 additions (all nullable, unused by ordinary entries): an override
 * event also names its crew and day, and carries HR's review decision, since
 * TC-04 makes the audit entry itself the record of the override.
 */
class AuditLog extends Model
{
    public const LATE_OVERRIDE = 'FOREMAN_LATE_OVERRIDE';

    public const MANUAL_TIME_OVERRIDE = 'MANUAL_TIME_OVERRIDE';

    /** Action types that group override records and go through HR review. */
    public const OVERRIDE_TYPES = [self::LATE_OVERRIDE, self::MANUAL_TIME_OVERRIDE];

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    protected $primaryKey = 'audit_id';

    protected $fillable = [
        'actor_id',
        'crew_id',
        'subject_date',
        'action_type',
        'description',
        'timestamp',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        // subject_date is deliberately NOT cast: a date cast stores
        // "Y-m-d 00:00:00" on SQLite, and the per-crew-day lookup compares
        // against a plain "Y-m-d", so every sync would create a duplicate event.
        return [
            'timestamp' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_id', 'employee_id');
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'crew_id', 'crew_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by', 'employee_id');
    }

    /** The attendance records an override event credited. */
    public function overriddenAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'override_audit_id', 'audit_id');
    }

    public function scopeOverrideEvents(Builder $query): Builder
    {
        return $query->whereIn('action_type', self::OVERRIDE_TYPES);
    }

    public function isOverrideEvent(): bool
    {
        return in_array($this->action_type, self::OVERRIDE_TYPES, true);
    }

    /** Human-facing reference, e.g. OVR-2026-0014, as shown in the prototype. */
    public function overrideCode(): string
    {
        return sprintf('OVR-%s-%04d', $this->timestamp?->format('Y') ?? now()->format('Y'), $this->audit_id);
    }
}
