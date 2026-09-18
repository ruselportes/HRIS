<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A proclaimed holiday (Phase 8). Not in the ERD: holidays are issued yearly
 * by Executive Order and some dates move, so they have to be data.
 */
class Holiday extends Model
{
    public const REGULAR = 'regular';

    public const SPECIAL = 'special';

    protected $primaryKey = 'holiday_id';

    // date stays the stored Y-m-d string: the engine matches days by that
    // string, and a date cast would append a time on SQLite.
    protected $fillable = ['date', 'name', 'type'];
}
