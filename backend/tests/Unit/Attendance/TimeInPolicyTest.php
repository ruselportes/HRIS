<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\TimeInPolicy;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the time_in rules behind STD TC-04 (Late Foreman Override): which
 * credited times a genuine, correctly signed event is permitted to carry.
 */
class TimeInPolicyTest extends TestCase
{
    private TimeInPolicy $policy;

    private const DATE = '2026-09-14';

    private const MINUTE = 60_000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'attendance.shift_start' => '07:00',
            'attendance.late_override_grace_minutes' => 15,
            'attendance.timezone' => 'Asia/Manila',
            'attendance.time_in_capture_tolerance_seconds' => 5,
        ]);

        $this->policy = new TimeInPolicy;
    }

    private function shiftStart(): int
    {
        return $this->policy->shiftStartMs(self::DATE);
    }

    private function event(array $overrides = []): array
    {
        $tap = $this->shiftStart() + 30 * self::MINUTE; // 07:30

        return array_merge([
            'date' => self::DATE,
            'status' => 'present',
            'time_in' => $tap,
            'captured_at' => $tap,
            'override_type' => null,
        ], $overrides);
    }

    private function assertRefused(string $reason, array $event): void
    {
        $result = $this->policy->evaluate($event);

        $this->assertFalse($result['valid'], "Expected refusal [{$reason}], got a pass.");
        $this->assertSame($reason, $result['reason']);
    }

    private function assertAllowed(array $event): void
    {
        $result = $this->policy->evaluate($event);

        $this->assertTrue($result['valid'], 'Expected a pass, got ['.($result['reason'] ?? 'null').'].');
    }

    public function test_shift_start_is_07_00_in_manila_not_in_utc(): void
    {
        // 07:00 +08:00 is 23:00 UTC the previous day. app.timezone is UTC, so
        // resolving in the wrong zone would put shift start eight hours late.
        $this->assertSame(
            Carbon::parse('2026-09-13 23:00:00', 'UTC')->getTimestampMs(),
            $this->shiftStart(),
        );
    }

    /* ---- ordinary taps ------------------------------------------------- */

    public function test_an_ordinary_tap_is_allowed(): void
    {
        $this->assertAllowed($this->event());
    }

    public function test_the_few_milliseconds_between_reading_time_in_and_capture_are_tolerated(): void
    {
        $event = $this->event();
        $event['captured_at'] += 40;

        $this->assertAllowed($event);
    }

    /**
     * The hole this class closes: with the clock check on captured_at, nothing
     * else looks at time_in. Without this, a signed event could credit any time.
     */
    public function test_an_ordinary_tap_cannot_credit_a_time_other_than_the_tap(): void
    {
        $event = $this->event();
        $event['time_in'] -= 90 * self::MINUTE;

        $this->assertRefused('time_in_does_not_match_tap', $event);
    }

    public function test_present_without_a_time_in_is_refused(): void
    {
        $this->assertRefused('arrival_without_time_in', $this->event(['time_in' => null]));
    }

    /* ---- absent and undo ---------------------------------------------- */

    public function test_absent_with_no_time_and_no_override_is_allowed(): void
    {
        $this->assertAllowed($this->event(['status' => 'absent', 'time_in' => null]));
    }

    public function test_undo_back_to_pending_is_allowed(): void
    {
        $this->assertAllowed($this->event(['status' => 'pending', 'time_in' => null]));
    }

    public function test_absent_cannot_carry_a_time_in(): void
    {
        $this->assertRefused('time_in_without_arrival', $this->event(['status' => 'absent']));
    }

    public function test_absent_cannot_carry_an_override(): void
    {
        $this->assertRefused('override_without_arrival', $this->event([
            'status' => 'absent',
            'time_in' => null,
            'override_type' => 'shift_credit',
        ]));
    }

    /* ---- shift_credit: TC-04 ------------------------------------------ */

    /** TC-04 itself: foreman opens roll call at 09:20, crew credited 07:00. */
    public function test_a_late_foreman_shift_credit_is_allowed(): void
    {
        $this->assertAllowed($this->event([
            'time_in' => $this->shiftStart(),
            'captured_at' => $this->shiftStart() + 140 * self::MINUTE, // 09:20
            'override_type' => 'shift_credit',
        ]));
    }

    public function test_a_shift_credit_must_be_exactly_shift_start(): void
    {
        // The credit is 07:00, not "whatever time the foreman prefers".
        $this->assertRefused('shift_credit_time_not_shift_start', $this->event([
            'time_in' => $this->shiftStart() - 30 * self::MINUTE, // 06:30
            'captured_at' => $this->shiftStart() + 140 * self::MINUTE,
            'override_type' => 'shift_credit',
        ]));
    }

    public function test_a_shift_credit_inside_the_grace_window_is_refused(): void
    {
        // 07:10 — the foreman is not actually late, so real tap times exist.
        $this->assertRefused('shift_credit_not_late', $this->event([
            'time_in' => $this->shiftStart(),
            'captured_at' => $this->shiftStart() + 10 * self::MINUTE,
            'override_type' => 'shift_credit',
        ]));
    }

    public function test_a_shift_credit_exactly_at_the_end_of_grace_is_allowed(): void
    {
        $this->assertAllowed($this->event([
            'time_in' => $this->shiftStart(),
            'captured_at' => $this->shiftStart() + 15 * self::MINUTE,
            'override_type' => 'shift_credit',
        ]));
    }

    public function test_a_shift_credit_cannot_be_applied_to_a_late_worker(): void
    {
        $this->assertRefused('shift_credit_requires_present', $this->event([
            'status' => 'late',
            'time_in' => $this->shiftStart(),
            'captured_at' => $this->shiftStart() + 140 * self::MINUTE,
            'override_type' => 'shift_credit',
        ]));
    }

    public function test_a_shift_credit_recorded_on_a_later_day_is_refused(): void
    {
        // Crediting yesterday from a phone is retroactive recovery, not an override.
        $this->assertRefused('override_outside_day', $this->event([
            'time_in' => $this->shiftStart(),
            'captured_at' => $this->shiftStart() + 26 * 60 * self::MINUTE,
            'override_type' => 'shift_credit',
        ]));
    }

    /* ---- manual_time -------------------------------------------------- */

    public function test_a_manual_arrival_time_earlier_the_same_day_is_allowed(): void
    {
        $this->assertAllowed($this->event([
            'status' => 'late',
            'time_in' => $this->shiftStart() + 50 * self::MINUTE,   // 07:50
            'captured_at' => $this->shiftStart() + 140 * self::MINUTE, // entered 09:20
            'override_type' => 'manual_time',
        ]));
    }

    public function test_a_manual_arrival_time_cannot_be_in_the_future(): void
    {
        $this->assertRefused('manual_time_in_future', $this->event([
            'time_in' => $this->shiftStart() + 200 * self::MINUTE,
            'captured_at' => $this->shiftStart() + 140 * self::MINUTE,
            'override_type' => 'manual_time',
        ]));
    }

    public function test_a_manual_arrival_time_must_fall_on_the_roll_call_day(): void
    {
        $this->assertRefused('manual_time_outside_day', $this->event([
            'time_in' => $this->shiftStart() - 10 * 60 * self::MINUTE, // previous evening
            'captured_at' => $this->shiftStart() + 140 * self::MINUTE,
            'override_type' => 'manual_time',
        ]));
    }

    public function test_an_unknown_override_type_is_refused(): void
    {
        $this->assertRefused('unknown_override_type', $this->event(['override_type' => 'honour_system']));
    }
}
