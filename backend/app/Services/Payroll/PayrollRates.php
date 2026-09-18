<?php

namespace App\Services\Payroll;

use RuntimeException;

/**
 * Effective-dated payroll rates (Phase 8).
 *
 * Every rate set in config/payroll.php carries a 'from' date. This returns
 * the set in force on the date being paid, so re-running an old period
 * reproduces what was owed then instead of silently applying today's rates.
 */
class PayrollRates
{
    /** Premium multipliers in force on a day. */
    public function premiumsOn(string $date): array
    {
        return $this->inForce(config('payroll.premiums', []), $date, 'premiums');
    }

    /** A statutory table (sss, philhealth, pagibig, withholding) in force on a day. */
    public function statutoryOn(string $table, string $date): array
    {
        return $this->inForce(config("payroll.statutory.{$table}", []), $date, $table);
    }

    private function inForce(array $sets, string $date, string $name): array
    {
        $found = null;

        foreach ($sets as $set) {
            if ($set['from'] <= $date && ($found === null || $set['from'] > $found['from'])) {
                $found = $set;
            }
        }

        if ($found === null) {
            throw new RuntimeException("No {$name} rates are in force on {$date}. Add a set to config/payroll.php.");
        }

        return $found;
    }
}
