<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD: tbl_crew (crew_id, site_id → site, foreman_id → employee [self-ref],
 * crew_name) + Phase 3 deployment state (status, deployed_at) + Phase 7 acting
 * cover (regular_foreman_id, acting_until).
 *
 * foreman_id is always whoever leads the crew NOW — during a cover that is the
 * acting foreman — so every existing "whose crew is this?" query stays right.
 */
class Crew extends Model
{
    use HasFactory;

    protected $primaryKey = 'crew_id';

    protected $fillable = [
        'site_id',
        'foreman_id',
        'regular_foreman_id',
        'acting_until',
        'crew_name',
        'status',
        'deployed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'deployed_at' => 'datetime',
            'acting_until' => 'datetime',
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

    /** The foreman who gets the crew back when an acting cover ends. */
    public function regularForeman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'regular_foreman_id', 'employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CrewAssignment::class, 'crew_id', 'crew_id');
    }

    /**
     * Active members — the roster. Leadership rows share the table (Phase 7)
     * but are not members, so they are filtered out here.
     */
    public function activeMembers(): HasMany
    {
        return $this->assignments()
            ->where('assignment_type', CrewAssignment::TYPE_MEMBER)
            ->where('status', 'active');
    }

    /** Who led the crew, and when (Phase 7). */
    public function leadershipHistory(): HasMany
    {
        return $this->assignments()
            ->whereIn('assignment_type', CrewAssignment::LEADERSHIP_TYPES)
            ->orderBy('started_at');
    }

    public function hasActingForeman(): bool
    {
        return $this->regular_foreman_id !== null;
    }
}
