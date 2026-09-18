<?php

/*
|--------------------------------------------------------------------------
| Payroll rules (Phase 8 — UC-08, STD TC-06)
|--------------------------------------------------------------------------
|
| Company policy and statutory figures, kept out of the computation code so a
| new wage order or circular is a config change, not a code change. See
| docs/PH_LABOR_AND_PAYROLL_EXPLAINED.md for where each figure comes from.
|
| [VERIFY] marks a figure set by an agency issuance that changes over time.
| Confirm each against the current issuance (the guide's §14 lists them)
| before this system pays anyone.
|
| Effective dating: rate sets below are lists ordered by 'from'. A payroll
| run uses the set in force for the DATE BEING PAID — premiums per day, the
| statutory tables per pay period — never today's. Re-running March in
| November must reproduce March. To change a rate, append a new set with a
| later 'from'; never edit a set that has already been paid on.
|
*/

return [

    /*
     | DOLE regional minimum wage, Region VII (Central Visayas), non-agriculture.
     | Floors Employee.daily_rate in validation, and decides who is a statutory
     | minimum wage earner — exempt from income tax under RA 9504. [VERIFY]
     */
    'regional_minimum_wage' => (float) env('HRIS_REGIONAL_MINIMUM_WAGE', 501.00),

    'rates' => [
        // Art. 83, PD 442 (the Labor Code — Presidential Decree 442, not "RA 442").
        'regular_hours_per_day' => 8.00,
    ],

    /*
     | Semi-monthly cut-offs, from the Payroll Run prototype: period A runs from
     | the 21st of the previous month to the 5th ("2026-09-A" = 21 Aug-05 Sep),
     | period B from the 6th to the 20th.
     */
    'cutoff_start_days' => [21, 6],

    /*
     | The paid day. Roll call records arrival only, so the shift frame says
     | when a day ends: 07:00-16:00 with the meal hour unpaid and not counted as
     | hours worked (Art. 85) — 8 paid hours. A Present worker is paid the full
     | 8; a Late one from their actual arrival ("no work, no pay"). Overtime is
     | paid only from an approved overtime request's time window.
     */
    'shift' => [
        // Start and end live in config/attendance.php, beside the rules that
        // enforce them; only the meal hour is payroll's alone.
        'meal_start' => env('HRIS_MEAL_START', '12:00'),
        'meal_end' => env('HRIS_MEAL_END', '13:00'),
    ],

    // The weekly rest day, ISO (7 = Sunday). Art. 91.
    'rest_day_iso' => (int) env('HRIS_REST_DAY_ISO', 7),

    /*
     | Night shift differential window, Art. 86.
     */
    'night' => ['start' => '22:00', 'end' => '06:00'],

    /*
     | Premium multipliers on the basic hourly rate (daily_rate / 8), per the
     | DOLE Handbook on Workers' Statutory Monetary Benefits. Applied in this
     | order: day type first, then overtime on top of the day rate, then night
     | differential on top of whichever rate applies. [VERIFY] against the
     | current Handbook.
     |
     |   day            first 8 h    overtime (x day rate)
     |   ordinary          1.00       1.25
     |   rest day          1.30       1.30  -> 1.69
     |   special day       1.30       1.30  -> 1.69
     |   special + rest    1.50       1.30  -> 1.95
     |   regular holiday   2.00       1.30  -> 2.60
     |   regular + rest    2.60       1.30  -> 3.38
     |   double regular    3.00       1.30  -> 3.90
     |   double + rest     3.90       1.30  -> 5.07
     |   night diff        x 1.10 on top of the applicable rate
     |
     | An UNWORKED regular holiday is paid at 100% (Art. 94) to an employee
     | present on the working day before it; an unworked special day is unpaid.
     */
    'premiums' => [
        [
            'from' => '2023-01-01',
            'day' => [
                'ordinary' => 1.00,
                'rest_day' => 1.30,
                'special' => 1.30,
                'special_rest_day' => 1.50,
                'regular_holiday' => 2.00,
                'regular_holiday_rest_day' => 2.60,
                'double_holiday' => 3.00,
                'double_holiday_rest_day' => 3.90,
            ],
            'overtime' => 1.25,
            'overtime_on_premium_day' => 1.30,
            'night_differential' => 1.10,
            'unworked_regular_holiday' => 1.00,
        ],
    ],

    /*
     | Statutory contributions and withholding tax.
     |
     | All three contributions are monthly. Pay is semi-monthly and daily-paid,
     | so each cut-off projects a month from its own earnings (x2), finds the
     | monthly contribution on that, and deducts half. Recorded as a policy
     | choice; a month-end true-up would be the refinement.
     */
    'statutory' => [

        /*
         | SSS, RA 11199. Bracketed by Monthly Salary Credit: compensation is
         | rounded to the nearest 500 within [msc_min, msc_max]. Employer also
         | pays EC (Employees' Compensation). [VERIFY] SSS Circular in force.
         */
        'sss' => [
            [
                'from' => '2025-01-01',
                'employee_rate' => 0.05,
                'employer_rate' => 0.10,
                'msc_min' => 5000,
                'msc_max' => 35000,
                'msc_step' => 500,
                'ec_low' => 10.00,
                'ec_high' => 30.00,
                'ec_high_from_msc' => 15000,
            ],
        ],

        /*
         | PhilHealth, RA 11223. A percentage of monthly basic salary within a
         | floor and ceiling, split equally. [VERIFY] PhilHealth Circular.
         */
        'philhealth' => [
            [
                'from' => '2024-01-01',
                'rate' => 0.05,
                'floor' => 10000,
                'ceiling' => 100000,
            ],
        ],

        /*
         | Pag-IBIG / HDMF, RA 9679. Employee 1% at or below the threshold, 2%
         | above; employer 2%; both on at most the maximum fund salary. [VERIFY]
         | HDMF circular — the cap was raised recently.
         */
        'pagibig' => [
            [
                'from' => '2024-02-01',
                'employee_rate_low' => 0.01,
                'low_threshold' => 1500,
                'employee_rate' => 0.02,
                'employer_rate' => 0.02,
                'max_fund_salary' => 10000,
            ],
        ],

        /*
         | Withholding tax, NIRC as amended by TRAIN (RA 10963), rates from
         | 2023. Annual brackets [over, base tax, rate on excess]; the
         | semi-monthly table is this divided by 24, as the BIR's own tables
         | are. [VERIFY] BIR withholding tables.
         */
        'withholding' => [
            [
                'from' => '2023-01-01',
                'periods_per_year' => 24,
                'annual_brackets' => [
                    [0, 0, 0.00],
                    [250000, 0, 0.15],
                    [400000, 22500, 0.20],
                    [800000, 102500, 0.25],
                    [2000000, 402500, 0.30],
                    [8000000, 2202500, 0.35],
                ],
            ],
        ],
    ],

];
