<?php

namespace Tests\Unit\Payroll;

use App\Services\Payroll\PayPeriod;
use InvalidArgumentException;
use Tests\TestCase;

class PayPeriodTest extends TestCase
{
    public function test_period_a_runs_from_the_21st_to_the_5th(): void
    {
        $period = PayPeriod::fromCode('2026-09-A');

        $this->assertSame(['2026-08-21', '2026-09-05'], [$period->start, $period->end]);
        $this->assertCount(16, $period->dates());
    }

    public function test_period_b_runs_from_the_6th_to_the_20th(): void
    {
        $period = PayPeriod::fromCode('2026-09-B');

        $this->assertSame(['2026-09-06', '2026-09-20'], [$period->start, $period->end]);
    }

    public function test_a_date_finds_its_period_across_the_year_end(): void
    {
        $this->assertSame('2026-09-A', PayPeriod::containing('2026-08-25')->code);
        $this->assertSame('2026-09-A', PayPeriod::containing('2026-09-05')->code);
        $this->assertSame('2026-09-B', PayPeriod::containing('2026-09-06')->code);

        $january = PayPeriod::containing('2026-12-22');
        $this->assertSame(['2027-01-A', '2026-12-21', '2027-01-05'], [$january->code, $january->start, $january->end]);
    }

    public function test_a_bad_code_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PayPeriod::fromCode('2026-09');
    }
}
