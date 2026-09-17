<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shift rules (Phase 7 — UC-05 Late Foreman Override)
    |--------------------------------------------------------------------------
    |
    | Operational rules for roll call, kept in config for the same reason as
    | crypto.php and payroll.php: they are company policy, not physical facts,
    | and a value buried in code goes stale silently.
    |
    */

    /*
     | The site shift start credited by a late foreman override. STD TC-04 and
     | the Late Override prototype both fix this at 07:00. A per-site value can
     | replace this later without changing the rule that reads it.
     */
    'shift_start' => env('HRIS_SHIFT_START', '07:00'),

    /*
     | How long after shift start the override is first offered. The prototype
     | triggers on "first roll call opened after 07:15", so a foreman a few
     | minutes behind records real tap times instead of reaching for a credit.
     | Also enforced server-side: a shift_credit captured inside the grace
     | window is refused, since the foreman was not actually late.
     */
    'late_override_grace_minutes' => (int) env('HRIS_LATE_OVERRIDE_GRACE_MINUTES', 15),

    /*
     | The timezone shift times are expressed in. Deliberately NOT app.timezone,
     | which is UTC: "07:00" means 07:00 on site in Cebu. The Philippines has
     | no DST, so this is a fixed UTC+08:00 and the device can compute the same
     | instant without a timezone database.
     */
    'timezone' => env('HRIS_ATTENDANCE_TIMEZONE', 'Asia/Manila'),

    /*
     | For an ordinary tap, time_in must match the moment the tap was captured.
     | They are read a few milliseconds apart on the device, so this is a
     | tolerance for that gap, not for clock drift — drift is the clock
     | verifier's job. Without this rule, moving the clock check onto
     | captured_at would leave time_in itself unchecked.
     */
    'time_in_capture_tolerance_seconds' => (int) env('HRIS_TIME_IN_CAPTURE_TOLERANCE', 5),

];
