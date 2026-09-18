<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * The 2026 Philippine holiday calendar, for development (Phase 8).
 *
 * [VERIFY] against the President's proclamation for 2026 before any payroll
 * is paid on it. The movable Islamic holidays, Eid'l Fitr and Eid'l Adha, are
 * proclaimed separately once their dates are fixed and are not seeded — HR
 * adds them on the Payroll page when announced.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            ['2026-01-01', "New Year's Day", Holiday::REGULAR],
            ['2026-02-17', 'Chinese New Year', Holiday::SPECIAL],
            ['2026-04-02', 'Maundy Thursday', Holiday::REGULAR],
            ['2026-04-03', 'Good Friday', Holiday::REGULAR],
            ['2026-04-04', 'Black Saturday', Holiday::SPECIAL],
            ['2026-04-09', 'Araw ng Kagitingan', Holiday::REGULAR],
            ['2026-05-01', 'Labor Day', Holiday::REGULAR],
            ['2026-06-12', 'Independence Day', Holiday::REGULAR],
            ['2026-08-21', 'Ninoy Aquino Day', Holiday::SPECIAL],
            ['2026-08-31', 'National Heroes Day', Holiday::REGULAR],
            ['2026-11-01', "All Saints' Day", Holiday::SPECIAL],
            ['2026-11-02', "All Souls' Day", Holiday::SPECIAL],
            ['2026-11-30', 'Bonifacio Day', Holiday::REGULAR],
            ['2026-12-08', 'Feast of the Immaculate Conception', Holiday::SPECIAL],
            ['2026-12-24', 'Christmas Eve', Holiday::SPECIAL],
            ['2026-12-25', 'Christmas Day', Holiday::REGULAR],
            ['2026-12-30', 'Rizal Day', Holiday::REGULAR],
            ['2026-12-31', 'Last Day of the Year', Holiday::SPECIAL],
        ];

        foreach ($holidays as [$date, $name, $type]) {
            Holiday::query()->updateOrCreate(['date' => $date, 'name' => $name], ['type' => $type]);
        }
    }
}
