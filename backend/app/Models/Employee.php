<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * ERD: tbl_employee — the HRIS "user". There is deliberately no separate
 * users/login table; RBAC runs off employee.role_id → Role. A null password
 * means the account cannot sign in (most field workers never log in).
 */
class Employee extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $primaryKey = 'employee_id';

    protected $fillable = [
        'role_id',
        'site_id',
        'employee_code',
        'email',
        'password',
        'first_name',
        'last_name',
        'middle_name',
        'trade_skill',
        'daily_rate',
        'certification',
        'emergency_contact',
        'employment_status',
        'date_of_birth',
        'mobile',
        'civil_status',
        'dependents',
        'address',
        'blood_type',
        'tin',
        'sss',
        'philhealth',
        'pag_ibig',
        'date_hired',
        'cost_centre',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'date_of_birth' => 'date',
            'date_hired' => 'date',
            'dependents' => 'integer',
            'certification' => 'array',
            'emergency_contact' => 'array',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }

    public function crewAssignments(): HasMany
    {
        return $this->hasMany(CrewAssignment::class, 'employee_id', 'employee_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->last_name}, {$this->first_name}".($this->middle_name ? " {$this->middle_name[0]}." : ''));
    }

    public function getDisplayNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function canSignIn(): bool
    {
        return $this->password !== null && $this->role?->isLoginRole();
    }
}
