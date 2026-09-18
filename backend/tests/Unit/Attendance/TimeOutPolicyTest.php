<?php

namespace Tests\Unit\Attendance;

use App\Models\Attendance;
use App\Services\Attendance\TimeOutPolicy;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The time-out rules on their own, against an unsaved record. Sat 12 Sep
 * 2026, site time; the shift ends at 16:00.
 */
class TimeOutPolicyTest extends TestCase
{
    private TimeOutPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new TimeOutPolicy;
    }

    public function test_shift_end_is_16_00_site_time(): void
    {
        // 16:00 +08:00 is 08:00 UTC.
        $this->assertSame(Carbon::parse('2026-09-12 08:00', 'UTC')->getTimestampMs(), $this->policy->shiftEndMs('2026-09-12'));
    }

    public function test_an_ordinary_out_must_be_the_tap_itself(): void
    {
        $this->assertTrue($this->evaluate('11:00', '11:00')['valid']);
        $this->assertSame('time_out_does_not_match_tap', $this->evaluate('11:00', '10:00')['reason']);
    }

    public function test_close_shift_is_exactly_shift_end_and_not_before_it(): void
    {
        $this->assertTrue($this->evaluate('16:00', '16:00', 'shift_end')['valid']);
        $this->assertTrue($this->evaluate('21:00', '16:00', 'shift_end')['valid']);
        $this->assertSame('shift_end_before_shift_end', $this->evaluate('15:59', '16:00', 'shift_end')['reason']);
        $this->assertSame('shift_end_time_not_shift_end', $this->evaluate('17:00', '16:30', 'shift_end')['reason']);
    }

    public function test_a_manual_time_out_cannot_be_later_than_when_it_was_entered(): void
    {
        $this->assertTrue($this->evaluate('18:30', '16:00', 'manual_time')['valid']);
        $this->assertSame('time_out_in_future', $this->evaluate('15:00', '16:00', 'manual_time')['reason']);
    }

    public function test_a_time_out_must_be_after_the_time_in_and_on_the_day(): void
    {
        $this->assertSame('time_out_before_time_in', $this->evaluate('09:00', '06:30', 'manual_time')['reason']);
        $this->assertSame('time_out_outside_day', $this->evaluate('23:00', '2026-09-11 18:00', 'manual_time')['reason']);
        // Entered the next morning: a day already over is recovery's, not the phone's.
        $this->assertSame('override_outside_day', $this->evaluate('2026-09-13 07:00', '16:00', 'manual_time')['reason']);
    }

    public function test_only_a_worker_on_roll_call_can_be_timed_out(): void
    {
        $this->assertSame('time_out_without_arrival', $this->evaluate('11:00', '11:00', record: null)['reason']);
        $this->assertSame('time_out_without_arrival', $this->evaluate('11:00', '11:00', record: $this->record('absent'))['reason']);
        $this->assertSame('time_out_status_mismatch', $this->evaluate('11:00', '11:00', record: $this->record('late'))['reason']);
    }

    public function test_clearing_needs_no_arrival_and_no_type(): void
    {
        $this->assertTrue($this->evaluate('11:00', null, record: null)['valid']);
        $this->assertSame('clearing_with_time_out_type', $this->evaluate('11:00', null, 'shift_end')['reason']);
    }

    public function test_a_time_out_restates_nothing_about_arrival(): void
    {
        $event = $this->event('11:00', '11:00') + [];
        $event['time_in'] = Carbon::parse('2026-09-12 07:00', 'Asia/Manila')->getTimestampMs();

        $this->assertSame('time_out_carries_time_in', $this->policy->evaluate($event, $this->record())['reason']);
    }

    private function evaluate(string $tapped, ?string $timeOut, ?string $type = null, ?Attendance $record = null): array
    {
        return $this->policy->evaluate(
            $this->event($tapped, $timeOut, $type),
            func_num_args() >= 4 ? $record : $this->record(),
        );
    }

    private function event(string $tapped, ?string $timeOut, ?string $type = null): array
    {
        return [
            'date' => '2026-09-12',
            'status' => 'present',
            'time_in' => null,
            'override_type' => null,
            'captured_at' => $this->ms($tapped),
            'time_out' => $timeOut === null ? null : $this->ms($timeOut),
            'time_out_type' => $type,
        ];
    }

    private function record(string $status = 'present'): Attendance
    {
        return new Attendance([
            'status' => $status,
            'time_in' => $status === 'absent' ? null : Carbon::parse('2026-09-12 07:00', 'Asia/Manila')->utc(),
        ]);
    }

    private function ms(string $time): int
    {
        $full = strlen($time) > 5 ? $time : "2026-09-12 {$time}";

        return Carbon::parse($full, 'Asia/Manila')->getTimestampMs();
    }
}
