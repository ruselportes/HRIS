<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * ERD: tbl_attendance (attendance_id, employee_id FK, crew_id FK, time_in,
 * time_out, monotonic_timestamp, sync_status, override_flag).
 *
 * One row per employee per day — the FINAL state. The device sends an
 * append-only event log, so several events can collapse into this one row;
 * the last accepted event wins. Each event is still verified individually,
 * because the chain requires it.
 */
class Attendance extends Model
{
    use HasFactory;

    /** A time-out credited at shift end by Close shift. */
    public const TIME_OUT_SHIFT_END = 'shift_end';

    /** A time-out the foreman stated; reviewed by HR. */
    public const TIME_OUT_MANUAL = 'manual_time';

    /** sync_status and override_flag of a record rebuilt through recovery (UC-07). */
    public const RECONSTRUCTED = 'reconstructed';

    protected $primaryKey = 'attendance_id';

    protected $fillable = [
        'employee_id',
        'crew_id',
        'time_in',
        'captured_at',
        'time_out',
        'time_out_type',
        'time_out_captured_at',
        'time_out_audit_id',
        'monotonic_timestamp',
        'sync_status',
        'override_flag',
        'override_audit_id',
        'date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'time_in' => 'datetime',
            'captured_at' => 'datetime',
            'time_out' => 'datetime',
            'time_out_captured_at' => 'datetime',
            'monotonic_timestamp' => 'integer',
        ];
    }

    /** HR's review of a manual time-out on this record. */
    public function timeOutEvent(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class, 'time_out_audit_id', 'audit_id');
    }

    /** The override event (audit entry) this record's credited time belongs to. */
    public function overrideEvent(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class, 'override_audit_id', 'audit_id');
    }

    /**
     * Whether payroll may use this record at all (Phase 7, feeding Phase 8).
     *
     * A record must carry a verified signature. Beyond that, an overridden
     * record is held back until HR has decided on its override event: pending
     * means nobody has checked the credited time yet, so it is not paid on.
     */
    public function isPayrollReady(): bool
    {
        // Reconstructed (UC-07): no device ever signed it, so the two human
        // signatures are the whole of its trust — paid only once HR has signed.
        if ($this->isReconstructed()) {
            return $this->overrideEvent?->review_status === AuditLog::REVIEW_APPROVED;
        }

        if ($this->cryptoSignature?->verified !== true) {
            return false;
        }

        // A time-out the foreman stated is held, like a stated time in, until
        // HR has decided on it.
        if ($this->time_out_type === self::TIME_OUT_MANUAL && ! in_array(
            $this->timeOutEvent?->review_status,
            [AuditLog::REVIEW_APPROVED, AuditLog::REVIEW_REJECTED],
            true,
        )) {
            return false;
        }

        if ($this->override_flag === null) {
            return true;
        }

        return in_array(
            $this->overrideEvent?->review_status,
            [AuditLog::REVIEW_APPROVED, AuditLog::REVIEW_REJECTED],
            true,
        );
    }

    /**
     * The arrival time payroll should use, or null if it cannot use one yet.
     *
     * Approved: the credited time stands. Rejected: HR did not accept the
     * credit, so the worker is paid from when the tap really happened — "rejecting
     * reverts to the actual tap times". Nothing is rewritten; the decision
     * governs which recorded time counts, so both stay recoverable.
     */
    public function effectiveTimeIn(): ?Carbon
    {
        if (! $this->isPayrollReady()) {
            return null;
        }

        if (! $this->isReconstructed()
            && $this->override_flag !== null
            && $this->overrideEvent?->review_status === AuditLog::REVIEW_REJECTED) {
            return $this->captured_at;
        }

        return $this->time_in;
    }

    /**
     * Rebuilt by a Site Engineer for a day with no roll call (UC-07), rather
     * than captured and signed on a foreman's phone.
     */
    public function isReconstructed(): bool
    {
        return $this->sync_status === self::RECONSTRUCTED;
    }

    /**
     * The time-out payroll should use, or null for none. A rejected manual
     * time-out falls back to when it was actually entered, as a rejected time
     * in falls back to the real tap.
     */
    public function effectiveTimeOut(): ?Carbon
    {
        if (! $this->isPayrollReady() || $this->time_out === null) {
            return null;
        }

        if ($this->time_out_type === self::TIME_OUT_MANUAL
            && $this->timeOutEvent?->review_status === AuditLog::REVIEW_REJECTED) {
            return $this->time_out_captured_at;
        }

        return $this->time_out;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'crew_id', 'crew_id');
    }

    /** 1:1 per the ERD — the signature of the last accepted event. */
    public function cryptoSignature(): HasOne
    {
        return $this->hasOne(CryptoSignature::class, 'attendance_id', 'attendance_id');
    }
}
