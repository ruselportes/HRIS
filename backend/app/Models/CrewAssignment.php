<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_crew_assignment (assignment_id, crew_id → crew,
 * employee_id → employee, date_assigned, status) + Phase 7 leadership rows
 * (assignment_type, started_at, ended_at).
 *
 * A member row is a worker on the roster. A leadership row is a period during
 * which one foreman led the crew; it is ended rather than deleted, which is the
 * history STD TC-05 checks.
 */
class CrewAssignment extends Model
{
    use HasFactory;

    public const TYPE_MEMBER = 'member';

    public const TYPE_FOREMAN = 'foreman';

    public const TYPE_ACTING_FOREMAN = 'acting_foreman';

    public const LEADERSHIP_TYPES = [self::TYPE_FOREMAN, self::TYPE_ACTING_FOREMAN];

    public const STATUS_ENDED = 'ended';

    protected $primaryKey = 'assignment_id';

    protected $fillable = [
        'crew_id',
        'employee_id',
        'assignment_type',
        'date_assigned',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'date_assigned' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'crew_id', 'crew_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
