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
 * TC-04 makes the audit entry itself the record of the override. A recovery
 * case (UC-07) works the same way, with its cause in reason_code.
 */
class AuditLog extends Model
{
    public const LATE_OVERRIDE = 'FOREMAN_LATE_OVERRIDE';

    public const MANUAL_TIME_OVERRIDE = 'MANUAL_TIME_OVERRIDE';

    /** A time-out the foreman stated rather than tapped (as in the Late Override Audit prototype). */
    public const MANUAL_TIME_OUT = 'MANUAL_TIME_OUT';

    /** A Site Engineer handed a crew to an acting foreman (UC-06, TC-05). */
    public const ACTING_FOREMAN_ASSIGNED = 'ACTING_FOREMAN_ASSIGNED';

    /** The cover ended — early by the engineer, or on its own at expiry. */
    public const ACTING_FOREMAN_ENDED = 'ACTING_FOREMAN_ENDED';

    /**
     * A reconstructed crew-day (UC-07): one per crew and day, signed by a Site
     * Engineer and then by HR. Its current state lives on this row.
     */
    public const RETROACTIVE_RECOVERY = 'RETROACTIVE_RECOVERY';

    /** Each step of a recovery, kept as its own entry so the history survives. */
    public const RECOVERY_SUBMITTED = 'RECOVERY_SUBMITTED';

    public const RECOVERY_RETURNED = 'RECOVERY_RETURNED';

    public const RECOVERY_SIGNED_OFF = 'RECOVERY_SIGNED_OFF';

    public const RECOVERY_STEPS = [self::RECOVERY_SUBMITTED, self::RECOVERY_RETURNED, self::RECOVERY_SIGNED_OFF];

    /** HR approved a payroll run's ready rows (Phase 8). */
    public const PAYROLL_APPROVED = 'PAYROLL_APPROVED';

    /** Action types that group override records and go through HR review. */
    public const OVERRIDE_TYPES = [self::LATE_OVERRIDE, self::MANUAL_TIME_OVERRIDE, self::MANUAL_TIME_OUT];

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /** Recovery only: HR sent it back to the engineer to correct. */
    public const REVIEW_RETURNED = 'returned';

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
        'reason_code',
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

    /** Records whose manual time-out this event reviews. */
    public function timeOutAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'time_out_audit_id', 'audit_id');
    }

    /** The records under review: time-outs for a manual time-out event, credited time ins otherwise. */
    public function reviewedAttendances()
    {
        return $this->action_type === self::MANUAL_TIME_OUT ? $this->timeOutAttendances : $this->overriddenAttendances;
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

    /** "REC-2026-0012" — how a recovery case is referred to on screen. */
    public function recoveryCode(): string
    {
        return sprintf('REC-%s-%04d', substr((string) $this->subject_date, 0, 4) ?: now()->format('Y'), $this->audit_id);
    }
}
