<?php

namespace App\Services\Payroll;

/**
 * Premium pay multipliers (Phase 8 — UC-08, STD TC-06).
 *
 * The order is the whole point, and the thing a payroll engine most often
 * gets wrong: the DAY TYPE sets the rate for the first 8 hours; OVERTIME is a
 * premium on top of that day rate (+25% on an ordinary day, +30% on any
 * premium day — Art. 87); NIGHT DIFFERENTIAL is +10% of whichever rate
 * applies (Art. 86). Premiums multiply; they never simply add.
 *
 * Pure: rates in, multipliers out. No database, no clock.
 */
class RateCalculator
{
    public const ORDINARY = 'ordinary';

    public const REST_DAY = 'rest_day';

    public const SPECIAL = 'special';

    public const SPECIAL_REST_DAY = 'special_rest_day';

    public const REGULAR_HOLIDAY = 'regular_holiday';

    public const REGULAR_HOLIDAY_REST_DAY = 'regular_holiday_rest_day';

    public const DOUBLE_HOLIDAY = 'double_holiday';

    public const DOUBLE_HOLIDAY_REST_DAY = 'double_holiday_rest_day';

    /**
     * The kind of day, from whether it is the employee's rest day and how
     * many regular and special holidays fall on it. Regular holidays outrank
     * special ones: a date that is both is paid as the regular holiday.
     */
    public function dayType(bool $restDay, int $regularHolidays, int $specialHolidays): string
    {
        if ($regularHolidays >= 2) {
            return $restDay ? self::DOUBLE_HOLIDAY_REST_DAY : self::DOUBLE_HOLIDAY;
        }

        if ($regularHolidays === 1) {
            return $restDay ? self::REGULAR_HOLIDAY_REST_DAY : self::REGULAR_HOLIDAY;
        }

        if ($specialHolidays > 0) {
            return $restDay ? self::SPECIAL_REST_DAY : self::SPECIAL;
        }

        return $restDay ? self::REST_DAY : self::ORDINARY;
    }

    /**
     * The multiplier on the basic hourly rate for one hour of work.
     *
     * @param  array  $premiums  a premium set from PayrollRates::premiumsOn()
     */
    public function multiplier(string $dayType, bool $overtime, bool $night, array $premiums): float
    {
        $rate = (float) $premiums['day'][$dayType];

        if ($overtime) {
            $rate *= $dayType === self::ORDINARY
                ? (float) $premiums['overtime']
                : (float) $premiums['overtime_on_premium_day'];
        }

        if ($night) {
            $rate *= (float) $premiums['night_differential'];
        }

        return round($rate, 6);
    }

    /** Pay for some hours at a multiplier, to the centavo. */
    public function pay(float $hourlyRate, float $hours, float $multiplier): float
    {
        return round($hourlyRate * $hours * $multiplier, 2);
    }
}
