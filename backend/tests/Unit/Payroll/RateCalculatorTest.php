<?php

namespace Tests\Unit\Payroll;

use App\Services\Payroll\PayrollRates;
use App\Services\Payroll\RateCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The premium matrix, one row per combination — the combinations payroll
 * engines most often get wrong (docs/PH_LABOR_AND_PAYROLL_EXPLAINED.md §4).
 */
class RateCalculatorTest extends TestCase
{
    private RateCalculator $calculator;

    private array $premiums;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new RateCalculator;
        $this->premiums = (new PayrollRates)->premiumsOn('2026-09-01');
    }

    public static function matrix(): array
    {
        return [
            'ordinary day' => [RateCalculator::ORDINARY, 1.00, 1.25],
            'rest day' => [RateCalculator::REST_DAY, 1.30, 1.69],
            'special day' => [RateCalculator::SPECIAL, 1.30, 1.69],
            'special day on a rest day' => [RateCalculator::SPECIAL_REST_DAY, 1.50, 1.95],
            'regular holiday' => [RateCalculator::REGULAR_HOLIDAY, 2.00, 2.60],
            'regular holiday on a rest day' => [RateCalculator::REGULAR_HOLIDAY_REST_DAY, 2.60, 3.38],
            'double regular holiday' => [RateCalculator::DOUBLE_HOLIDAY, 3.00, 3.90],
            'double regular holiday on a rest day' => [RateCalculator::DOUBLE_HOLIDAY_REST_DAY, 3.90, 5.07],
        ];
    }

    #[DataProvider('matrix')]
    public function test_first_eight_hours_and_overtime_follow_the_dole_matrix(string $dayType, float $day, float $overtime): void
    {
        $this->assertEqualsWithDelta($day, $this->calculator->multiplier($dayType, false, false, $this->premiums), 1e-9);
        $this->assertEqualsWithDelta($overtime, $this->calculator->multiplier($dayType, true, false, $this->premiums), 1e-9);
    }

    public function test_night_differential_is_ten_percent_of_the_applicable_rate_not_the_basic_one(): void
    {
        // Overtime at 2 AM on a regular holiday: 2.00 x 1.30 x 1.10.
        $this->assertEqualsWithDelta(2.86, $this->calculator->multiplier(RateCalculator::REGULAR_HOLIDAY, true, true, $this->premiums), 1e-9);

        // Ordinary-day overtime at night: 1.25 x 1.10 — not 1.25 + 0.10.
        $this->assertEqualsWithDelta(1.375, $this->calculator->multiplier(RateCalculator::ORDINARY, true, true, $this->premiums), 1e-9);
    }

    public function test_day_type_from_the_calendar(): void
    {
        $this->assertSame(RateCalculator::ORDINARY, $this->calculator->dayType(false, 0, 0));
        $this->assertSame(RateCalculator::REST_DAY, $this->calculator->dayType(true, 0, 0));
        $this->assertSame(RateCalculator::SPECIAL_REST_DAY, $this->calculator->dayType(true, 0, 1));
        $this->assertSame(RateCalculator::DOUBLE_HOLIDAY, $this->calculator->dayType(false, 2, 0));
        // A regular holiday outranks a special day on the same date.
        $this->assertSame(RateCalculator::REGULAR_HOLIDAY, $this->calculator->dayType(false, 1, 1));
    }

    public function test_pay_is_rounded_to_the_centavo(): void
    {
        // ₱600/day = ₱75/h; 2 h of night overtime = 2 x 75 x 1.375.
        $this->assertSame(206.25, $this->calculator->pay(75.0, 2, 1.375));
        $this->assertSame(0.0, $this->calculator->pay(75.0, 0, 1.25));
    }
}
