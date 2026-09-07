<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD: tbl_role (role_id, role_name, description, slug).
 * Slugs drive every RBAC decision — see EmployeePolicy / Gates.
 */
class Role extends Model
{
    use HasFactory;

    protected $primaryKey = 'role_id';

    public $timestamps = true;

    protected $fillable = [
        'role_name',
        'slug',
        'description',
    ];

    /**
     * Roles that get HRIS logins. Worker/Operator are employee-record roles
     * only — matching the prototype (no self-registration, admin-provisioned).
     */
    public const LOGIN_SLUGS = [
        'hr',
        'foreman',
        'engineer',
        'admin',
        'executive',
    ];

    public function isLoginRole(): bool
    {
        return in_array($this->slug, self::LOGIN_SLUGS, true);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'role_id', 'role_id');
    }
}