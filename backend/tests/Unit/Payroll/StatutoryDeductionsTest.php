<?php

namespace Tests\Unit\Payroll;

use App\Services\Payroll\PayrollRates;
use App\Services\Payroll\StatutoryDeductions;
use Tests\TestCase;

/**
 * Figures follow the rate sets in config/payroll.php, which are marked
 * [VERIFY]. These tests pin the MECHANISM — brackets, floors, caps, the order
 * of tax after contributions, the exemption — so a verified rate change only
 * moves the numbers, not the logic.
 */
class StatutoryDeductionsTest extends TestCase
{
    private StatutoryDeductions $statutory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statutory = new StatutoryDeductions(new PayrollRates);
    }

    public function test_a_higher_earner_worked_example(): void
    {
        // ₱20,000 this cut-off → ₱40,000 projected month.
        $result = $this->statutory->compute(20000, 20000, 1500, '2026-09-20');

        // SSS: MSC capped at ₱35,000; 5% employee, half per cut-off.
        $this->assertSame(875.0, $result['sss_employee']);
        // Employer 10% + ₱30 EC, halved.
        $this->assertSame(1765.0, $result['sss_employer']);
        // PhilHealth: 5% of ₱40,000, split with the employer, halved.
        $this->assertSame(500.0, $result['philhealth_employee']);
        // Pag-IBIG: 2% of the ₱10,000 maximum fund salary, halved.
        $this->assertSame(100.0, $result['pagibig_employee']);

        // Tax on 20,000 - 1,475 = 18,525, in the 20% semi-monthly bracket:
        // 22,500/24 + (18,525 - 400,000/24) x 0.20.
        $this->assertSame(1309.17, $result['withholding_tax']);
        $this->assertStringContainsString('BIR semi-monthly table', $result['tax_note']);
    }

    public function test_sss_rounds_to_the_nearest_salary_credit_within_the_floor(): void
    {
        // Projected ₱10,282.50 → MSC ₱10,500.
        $this->assertSame(262.5, $this->statutory->compute(5141.25, 3600, 600, '2026-09-05')['sss_employee']);

        // Projected ₱2,000 → the ₱5,000 floor.
        $this->assertSame(125.0, $this->statutory->compute(1000, 1000, 600, '2026-09-05')['sss_employee']);
    }

    public function test_philhealth_uses_its_floor_and_pagibig_its_low_rate(): void
    {
        $result = $this->statutory->compute(700, 700, 600, '2026-09-05');

        // Projected basic ₱1,400: PhilHealth floor ₱10,000 → ₱500 premium.
        $this->assertSame(125.0, $result['philhealth_employee']);
        // Pag-IBIG at or below ₱1,500 a month: 1% employee.
        $this->assertSame(7.0, $result['pagibig_employee']);
        $this->assertSame(14.0, $result['pagibig_employer']);
    }

    public function test_a_minimum_wage_earner_is_exempt_and_the_note_says_so(): void
    {
        $result = $this->statutory->compute(30000, 30000, 501, '2026-09-20');

        $this->assertSame(0.0, $result['withholding_tax']);
        $this->assertSame('Exempt: statutory minimum wage earner (RA 9504)', $result['tax_note']);
    }

    public function test_zero_tax_inside_the_zero_bracket_is_recorded_as_such(): void
    {
        $result = $this->statutory->compute(5141.25, 3600, 600, '2026-09-05');

        $this->assertSame(0.0, $result['withholding_tax']);
        $this->assertStringContainsString('zero bracket', $result['tax_note']);
    }

    public function test_no_earnings_no_contributions(): void
    {
        $result = $this->statutory->compute(0, 0, 600, '2026-09-05');

        $this->assertSame(0.0, $result['sss_employee'] + $result['philhealth_employee'] + $result['pagibig_employee']);
    }
}
