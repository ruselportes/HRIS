<?php

namespace App\Services\Payroll;

/**
 * SSS, PhilHealth, Pag-IBIG and withholding tax for one semi-monthly cut-off
 * (Phase 8). See docs/PH_LABOR_AND_PAYROLL_EXPLAINED.md §8-10.
 *
 * The contributions are monthly, but pay here is semi-monthly and daily-paid.
 * Policy: each cut-off projects a month from its own earnings (x2), finds the
 * monthly contribution on that, and deducts half. Tax comes after the
 * contributions, since they reduce taxable income.
 *
 * The tax note records WHY tax is what it is. "Exempt as a minimum wage
 * earner" and "inside the zero bracket" both produce ₱0, and an audit needs
 * to know which.
 */
class StatutoryDeductions
{
    public function __construct(private readonly PayrollRates $rates) {}

    /**
     * @param  float  $gross  this cut-off's gross pay
     * @param  float  $basic  this cut-off's basic pay (no premiums)
     * @param  string  $periodEnd  the cut-off's last day, which picks the tables
     * @return array<string, float|string>
     */
    public function compute(float $gross, float $basic, float $dailyRate, string $periodEnd): array
    {
        $monthlyGross = $gross * 2;
        $monthlyBasic = $basic * 2;

        $sss = $this->sss($monthlyGross, $periodEnd);
        $philhealth = $this->philhealth($monthlyBasic, $periodEnd);
        $pagibig = $this->pagibig($monthlyBasic, $periodEnd);

        $contributions = $sss['employee'] + $philhealth['employee'] + $pagibig['employee'];
        $tax = $this->withholding($gross - $contributions, $dailyRate, $periodEnd);

        return [
            'sss_employee' => $sss['employee'],
            'sss_employer' => $sss['employer'],
            'philhealth_employee' => $philhealth['employee'],
            'philhealth_employer' => $philhealth['employer'],
            'pagibig_employee' => $pagibig['employee'],
            'pagibig_employer' => $pagibig['employer'],
            'withholding_tax' => $tax['tax'],
            'tax_note' => $tax['note'],
        ];
    }

    /** @return array{employee:float, employer:float} this cut-off's half */
    private function sss(float $monthlyGross, string $date): array
    {
        if ($monthlyGross <= 0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $t = $this->rates->statutoryOn('sss', $date);
        $step = $t['msc_step'];
        $msc = min(max(floor(($monthlyGross + $step / 2) / $step) * $step, $t['msc_min']), $t['msc_max']);
        $ec = $msc >= $t['ec_high_from_msc'] ? $t['ec_high'] : $t['ec_low'];

        return [
            'employee' => round($msc * $t['employee_rate'] / 2, 2),
            'employer' => round(($msc * $t['employer_rate'] + $ec) / 2, 2),
        ];
    }

    /** @return array{employee:float, employer:float} */
    private function philhealth(float $monthlyBasic, string $date): array
    {
        if ($monthlyBasic <= 0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $t = $this->rates->statutoryOn('philhealth', $date);
        $premium = min(max($monthlyBasic, $t['floor']), $t['ceiling']) * $t['rate'];
        $share = round($premium / 2 / 2, 2);

        return ['employee' => $share, 'employer' => $share];
    }

    /** @return array{employee:float, employer:float} */
    private function pagibig(float $monthlyBasic, string $date): array
    {
        if ($monthlyBasic <= 0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $t = $this->rates->statutoryOn('pagibig', $date);
        $base = min($monthlyBasic, $t['max_fund_salary']);
        $employeeRate = $monthlyBasic <= $t['low_threshold'] ? $t['employee_rate_low'] : $t['employee_rate'];

        return [
            'employee' => round($base * $employeeRate / 2, 2),
            'employer' => round($base * $t['employer_rate'] / 2, 2),
        ];
    }

    /** @return array{tax:float, note:string} */
    private function withholding(float $taxable, float $dailyRate, string $date): array
    {
        if ($dailyRate <= (float) config('payroll.regional_minimum_wage')) {
            return ['tax' => 0.0, 'note' => 'Exempt: statutory minimum wage earner (RA 9504)'];
        }

        $t = $this->rates->statutoryOn('withholding', $date);
        $periods = $t['periods_per_year'];
        $tax = 0.0;

        foreach ($t['annual_brackets'] as [$over, $base, $rate]) {
            if ($taxable > $over / $periods) {
                $tax = $base / $periods + ($taxable - $over / $periods) * $rate;
            }
        }

        $tax = round(max(0, $tax), 2);

        return $tax > 0
            ? ['tax' => $tax, 'note' => 'Withheld per the BIR semi-monthly table (TRAIN, 2023 rates)']
            : ['tax' => 0.0, 'note' => 'None due: within the zero bracket (annual taxable income up to ₱250,000)'];
    }
}
