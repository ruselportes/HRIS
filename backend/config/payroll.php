<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Regional Minimum Wage
    |--------------------------------------------------------------------------
    |
    | DOLE regional minimum wage for Central Visayas (Region VII), issued under
    | the applicable Wage Order. This is a compliance figure that changes when a
    | new wage order takes effect — update it here, never in validation rules or
    | payroll math. It feeds the daily-rate floor in the employee form validation
    | and remains authoritative for later payroll computation phases.
    |
    */

    'regional_minimum_wage' => (float) env('HRIS_REGIONAL_MINIMUM_WAGE', 501.00),

    /*
    |--------------------------------------------------------------------------
    | Philippine Labor Code multipliers (RA 442)
    |--------------------------------------------------------------------------
    |
    | Codified here for the payroll engine (Phase 8). Recorded now so the config
    | is the single source of truth referenced by every later phase.
    |
    */

    'rates' => [
        'regular' => 1.00,   // base hourly rate
        'overtime' => 1.25,  // Art. 87 — work beyond 8 hours
        'night_differential' => 1.10, // Art. 86 — 10 PM to 6 AM
        'rest_day' => 1.30,  // Art. 93 — work on rest day
        'holiday' => 2.00,   // Art. 94 — work on legal holiday
        'regular_hours_per_day' => 8.00,
    ],

];