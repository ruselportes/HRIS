<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_crew_assignment (assignment_id, crew_id → crew,
 * employee_id → employee, date_assigned, status).
 */
class CrewAssignment extends Model
{
    protected $primaryKey = 'assignment_id';

    protected $fillable = [
        'crew_id',
        'employee_id',
        'date_assigned',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_assigned' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
