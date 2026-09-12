<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    protected $primaryKey = 'attendance_id';

    protected $fillable = [
        'employee_id',
        'crew_id',
        'time_in',
        'time_out',
        'monotonic_timestamp',
        'sync_status',
        'override_flag',
        'date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'monotonic_timestamp' => 'integer',
        ];
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
