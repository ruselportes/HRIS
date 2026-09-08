<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD: tbl_crew (crew_id, site_id → site, foreman_id → employee [self-ref],
 * crew_name) + Phase 3 deployment state (status, deployed_at).
 */
class Crew extends Model
{
    use HasFactory;

    protected $primaryKey = 'crew_id';

    protected $fillable = [
        'site_id',
        'foreman_id',
        'crew_name',
        'status',
        'deployed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'deployed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }

    public function foreman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'foreman_id', 'employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CrewAssignment::class, 'crew_id', 'crew_id');
    }

    /**
     * Active members (ordinary roster — the foreman is crews.foreman_id,
     * not a crew_assignment row in itself).
     */
    public function activeMembers(): HasMany
    {
        return $this->assignments()->where('status', 'active');
    }
}
