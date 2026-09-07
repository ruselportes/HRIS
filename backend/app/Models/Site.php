<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD: tbl_site (site_id, site_name, location, status).
 */
class Site extends Model
{
    use HasFactory;

    protected $primaryKey = 'site_id';

    protected $fillable = [
        'site_name',
        'location',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'site_id', 'site_id');
    }
}