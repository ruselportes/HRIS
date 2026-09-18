<?php

namespace Tests\Unit\Payroll;

use App\Services\Payroll\TimeWorked;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeWorkedTest extends TestCase
{
    private const SHIFT = ['start' => '07:00', 'end' => '16:00', 'meal_start' => '12:00', 'meal_end' => '13:00'];

    private const NIGHT = ['start' => '22:00', 'end' => '06:00'];

    private TimeWorked $time;

    protected function setUp(): void
    {
        $this->time = new TimeWorked;
    }

    public function test_present_is_the_full_eight_hours_with_the_meal_hour_unpaid(): void
    {
        $this->assertEqualsWithDelta(8.0, $this->time->regularHours('present', null, self::SHIFT), 1e-9);
    }

    public function test_late_is_paid_from_arrival(): void
    {
        // 08:10 to 16:00 is 7 h 50 m, less the meal hour.
        $this->assertEqualsWithDelta(6 + 50 / 60, $this->time->regularHours('late', 8 * 60 + 10, self::SHIFT), 1e-4);

        // Arriving after lunch: no meal hour to take out.
        $this->assertEqualsWithDelta(2.5, $this->time->regularHours('late', 13 * 60 + 30, self::SHIFT), 1e-9);
    }

    /** Hours stay exact, so the peso amount is rounded once, not the hours first. */
    public function test_hours_are_not_rounded_before_pay(): void
    {
        // 07:40 to 16:00 less the meal hour: 7 h 20 m.
        $hours = $this->time->regularHours('late', 7 * 60 + 40, self::SHIFT);

        $this->assertSame(22 / 3, $hours);
        // ₱580/day = ₱72.50/h; 7.3333 h would give 531.66.
        $this->assertSame(531.67, round(72.5 * $hours, 2));
    }

    public function test_absent_and_pending_are_not_paid(): void
    {
        $this->assertEquals(0, $this->time->regularHours('absent', null, self::SHIFT));
        $this->assertEquals(0, $this->time->regularHours('pending', null, self::SHIFT));
    }

    public static function windows(): array
    {
        return [
            'after the shift' => ['16:00', '18:00', 2.0, 0.0],
            'into the night' => ['20:00', '00:00', 4.0, 2.0],
            'across midnight' => ['22:00', '02:00', 4.0, 4.0],
            'early morning before the shift' => ['04:00', '07:00', 3.0, 2.0],
            'overlapping the shift' => ['15:00', '18:00', 2.0, 0.0],
        ];
    }

    #[DataProvider('windows')]
    public function test_overtime_windows(string $start, string $end, float $hours, float $night): void
    {
        $window = $this->time->overtime($start, $end, self::SHIFT, self::NIGHT);

        $this->assertEqualsWithDelta($hours, $window['hours'], 1e-9);
        $this->assertEqualsWithDelta($night, $window['night_hours'], 1e-9);
    }

    public static function timeOuts(): array
    {
        return [
            // status, arrival, left at, paid hours
            'left at 14:00' => ['present', null, '14:00', 6.0],
            'left during the meal hour' => ['present', null, '12:30', 5.0],
            'left after shift end' => ['present', null, '18:30', 8.0],
            'late and left early' => ['late', '08:00', '15:00', 6.0],
            'left before the shift began' => ['present', null, '06:50', 0.0],
        ];
    }

    #[DataProvider('timeOuts')]
    public function test_a_time_out_ends_the_paid_day(string $status, ?string $arrival, string $leftAt, float $hours): void
    {
        $paid = $this->time->regularHours(
            $status,
            $arrival === null ? null : $this->time->minutes($arrival),
            self::SHIFT,
            $this->time->minutes($leftAt),
        );

        $this->assertEqualsWithDelta($hours, $paid, 1e-9);
    }

    public static function cappedWindows(): array
    {
        return [
            // window, left at, hours, night hours
            'left partway' => ['16:00', '19:00', '18:00', 2.0, 0.0],
            'left before it began' => ['16:00', '18:00', '15:30', 0.0, 0.0],
            'left after it ended' => ['16:00', '18:00', '18:30', 2.0, 0.0],
            'left partway through the night hours' => ['20:00', '00:00', '23:00', 3.0, 1.0],
            'early-morning window, left in the afternoon' => ['04:00', '07:00', '16:00', 3.0, 2.0],
        ];
    }

    #[DataProvider('cappedWindows')]
    public function test_overtime_ends_when_the_worker_was_timed_out(
        string $start,
        string $end,
        string $leftAt,
        float $hours,
        float $night,
    ): void {
        $window = $this->time->overtime($start, $end, self::SHIFT, self::NIGHT, $this->time->minutes($leftAt));

        $this->assertEqualsWithDelta($hours, $window['hours'], 1e-9);
        $this->assertEqualsWithDelta($night, $window['night_hours'], 1e-9);
    }

    public function test_database_times_with_seconds_are_read(): void
    {
        $this->assertEqualsWithDelta(2.0, $this->time->overtime('16:00:00', '18:00:00', self::SHIFT, self::NIGHT)['hours'], 1e-9);
    }
}
