<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * Time-out capture through the real sync endpoint (payload v3). Every event
 * here is chained and signed by the test device, so each assertion is about
 * what the server does with authentic data.
 *
 * All on Sat 12 Sep 2026, site time; the shift is 07:00-16:00.
 */
class TimeOutCaptureTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    private const DATE = '2026-09-12';

    private int $workerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->at('06:00'));
        $this->setUpSignedDevice();
        $this->workerId = $this->worker()->employee_id;
        $this->travelTo($this->at('23:00'));
    }

    public function test_an_out_tapped_as_the_worker_leaves_is_recorded(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('11:00', '11:00'),
        ])->assertOk()->assertJsonPath('accepted', 2);

        $record = $this->record();
        $this->assertTrue($record->time_out->eq($this->at('11:00')));
        $this->assertNull($record->time_out_type);
        $this->assertTrue($record->effectiveTimeOut()->eq($this->at('11:00')));
    }

    public function test_close_shift_credits_exactly_the_shift_end(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('16:05', '16:00', 'shift_end'),
        ])->assertOk();

        $record = $this->record();
        $this->assertTrue($record->time_out->eq($this->at('16:00')));
        $this->assertSame('shift_end', $record->time_out_type);
        // Not reviewed: Close shift asserts only that nobody left early.
        $this->assertNull($record->time_out_audit_id);
        $this->assertTrue($record->isPayrollReady());
    }

    /** Crediting 16:00 at 15:00 would pay for an hour nobody has worked yet. */
    public function test_close_shift_before_the_shift_ends_is_refused(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('15:00', '16:00', 'shift_end'),
        ])->assertJsonPath('refused', 1)->assertJsonPath('results.1.reason', 'shift_end_before_shift_end');

        $this->assertNull($this->record()->time_out);
    }

    public function test_close_shift_credits_nothing_but_the_shift_end(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('18:05', '18:00', 'shift_end'),
        ])->assertJsonPath('results.1.reason', 'shift_end_time_not_shift_end');
    }

    public function test_a_manual_time_out_goes_to_hr_and_holds_the_record_until_decided(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('18:30', '16:00', 'manual_time'),
        ])->assertOk();

        $record = $this->record();
        $event = AuditLog::query()->where('action_type', AuditLog::MANUAL_TIME_OUT)->sole();
        $this->assertSame($event->audit_id, $record->time_out_audit_id);
        $this->assertSame(AuditLog::REVIEW_PENDING, $event->review_status);
        $this->assertFalse($record->isPayrollReady());

        $hr = $this->loginUser('hr');
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/overrides?type=MANUAL_TIME_OUT')
            ->assertOk()
            ->assertJsonPath('data.0.action_type', AuditLog::MANUAL_TIME_OUT)
            ->assertJsonPath('data.0.record_count', 1);

        $this->actingAs($hr, 'sanctum')->postJson("/api/overrides/{$event->audit_id}/approve")->assertOk();

        $record = $this->record();
        $this->assertTrue($record->isPayrollReady());
        $this->assertTrue($record->effectiveTimeOut()->eq($this->at('16:00')));
    }

    /** As a rejected time-in credit falls back to the real tap. */
    public function test_a_rejected_manual_time_out_falls_back_to_when_it_was_entered(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('18:30', '16:00', 'manual_time'),
        ])->assertOk();

        $event = AuditLog::query()->where('action_type', AuditLog::MANUAL_TIME_OUT)->sole();
        $this->actingAs($this->loginUser('hr'), 'sanctum')
            ->postJson("/api/overrides/{$event->audit_id}/reject", ['note' => 'Gate log shows 18:25.'])
            ->assertOk();

        $this->assertTrue($this->record()->effectiveTimeOut()->eq($this->at('18:30')));
    }

    /** One record, two reviews: a late-start credit on the time in and a manual time-out. */
    public function test_a_record_can_be_under_both_reviews_at_once(): void
    {
        $this->day([
            $this->rollCall('09:20', timeIn: '07:00', override: 'shift_credit'),
            $this->timeOut('18:30', '16:00', 'manual_time'),
        ])->assertOk()->assertJsonPath('accepted', 2);

        $record = $this->record();
        $this->assertNotNull($record->override_audit_id);
        $this->assertNotNull($record->time_out_audit_id);
        $this->assertNotSame($record->override_audit_id, $record->time_out_audit_id);

        $hr = $this->loginUser('hr');
        $this->actingAs($hr, 'sanctum')->postJson("/api/overrides/{$record->override_audit_id}/approve")->assertOk();
        $this->assertFalse($this->record()->isPayrollReady());

        $this->actingAs($hr, 'sanctum')->postJson("/api/overrides/{$record->time_out_audit_id}/approve")->assertOk();
        $this->assertTrue($this->record()->isPayrollReady());
    }

    public function test_an_absent_worker_has_nothing_to_time_out(): void
    {
        $this->day([
            $this->rollCall('06:58', status: 'absent'),
            $this->timeOut('11:00', '11:00', status: 'absent'),
        ])->assertJsonPath('results.1.reason', 'time_out_without_arrival');
    }

    public function test_a_time_out_before_the_time_in_is_refused(): void
    {
        $this->day([
            $this->rollCall('09:20', timeIn: '07:00', override: 'shift_credit'),
            $this->timeOut('09:30', '06:30', 'manual_time'),
        ])->assertJsonPath('results.1.reason', 'time_out_before_time_in');
    }

    public function test_undo_clears_the_time_out(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('11:00', '11:00'),
            $this->timeOut('11:02', null),
        ])->assertOk()->assertJsonPath('accepted', 3);

        $this->assertNull($this->record()->time_out);
        $this->assertNull($this->record()->time_out_captured_at);
    }

    /** A new roll call closes nothing: the time-out recorded against the old status goes. */
    public function test_re_marking_the_worker_clears_the_time_out(): void
    {
        $this->day([
            $this->rollCall('06:58'),
            $this->timeOut('11:00', '11:00'),
            $this->rollCall('11:05', status: 'late'),
        ])->assertOk();

        $this->assertNull($this->record()->time_out);
        $this->assertSame('late', $this->record()->status);
    }

    public function test_a_flagged_time_out_is_logged_but_not_applied(): void
    {
        // The wall clock jumps two hours between the two events while the
        // monotonic counter moves four minutes: the time-out's clock is untrusted.
        $events = $this->build([
            $this->rollCall('06:58'),
            $this->timeOut('11:00', '11:00', monotonicAt: '07:02'),
        ]);

        $this->sync($events)->assertJsonPath('flagged', 1);

        $record = $this->record();
        $this->assertNull($record->time_out);
        $this->assertTrue($record->cryptoSignature->verified);
        $this->assertSame(1, AuditLog::query()->where('action_type', 'ATTENDANCE_CLOCK_FLAGGED')->count());
    }

    /** A trusted time-out must never make a flagged time in look trusted. */
    public function test_a_time_out_never_launders_a_flagged_time_in(): void
    {
        $other = $this->worker()->employee_id;

        $events = $this->build([
            $this->rollCall('06:58'),
            // Flagged: two hours of wall clock in four minutes of monotonic time.
            $this->rollCall('09:00', employee: $other, monotonicAt: '07:02'),
            // Trusted again: consistent with the last trusted reading.
            $this->timeOut('11:00', '11:00', employee: $other),
        ]);

        $this->sync($events)->assertJsonPath('flagged', 1)->assertJsonPath('accepted', 2);

        $record = Attendance::query()->where('employee_id', $other)->sole();
        $this->assertTrue($record->time_out->eq($this->at('11:00')));
        $this->assertFalse($record->cryptoSignature->verified);
        $this->assertFalse($record->isPayrollReady());
    }

    /** event_type is only signed in v3; on a v2 event it is an unsigned key and means nothing. */
    public function test_a_v2_event_can_only_be_a_roll_call(): void
    {
        $events = $this->buildBatch([$this->workerId], null, [[
            'time_in' => $this->ms('06:58'),
        ]]);
        $events[0]['event_type'] = 'time_out';
        $events[0]['time_out'] = $this->ms('11:00');

        $this->sync($events)->assertOk()->assertJsonPath('accepted', 1);

        $this->assertNull($this->record()->time_out);
        $this->assertSame('present', $this->record()->status);
    }

    public function test_the_roster_endpoint_tells_the_phone_when_the_shift_ends(): void
    {
        $this->actingAs($this->foreman, 'sanctum')
            ->getJson('/api/me/crew')
            ->assertOk()
            ->assertJsonPath('shift.end', '16:00');
    }

    /* --------------------------------------------------------------------- */

    private function rollCall(
        string $tapped,
        ?string $timeIn = null,
        string $status = 'present',
        ?string $override = null,
        ?int $employee = null,
        ?string $monotonicAt = null,
    ): array {
        return [
            'employee' => $employee,
            'tapped' => $tapped,
            'monotonic_at' => $monotonicAt,
            'fields' => [
                'event_type' => 'roll_call',
                'status' => $status,
                'time_in' => $status === 'absent' ? null : $this->ms($timeIn ?? $tapped),
                'override_type' => $override,
                'time_out' => null,
                'time_out_type' => null,
            ],
        ];
    }

    private function timeOut(
        string $tapped,
        ?string $timeOut,
        ?string $type = null,
        string $status = 'present',
        ?int $employee = null,
        ?string $monotonicAt = null,
    ): array {
        return [
            'employee' => $employee,
            'tapped' => $tapped,
            'monotonic_at' => $monotonicAt,
            'fields' => [
                'event_type' => 'time_out',
                'status' => $status,
                'time_in' => null,
                'override_type' => null,
                'time_out' => $timeOut === null ? null : $this->ms($timeOut),
                'time_out_type' => $type,
            ],
        ];
    }

    /**
     * Chain and sign a day's events. The monotonic clock tracks the wall clock
     * (from the first tap) unless an event says otherwise.
     */
    private function build(array $specs): array
    {
        $first = $this->ms($specs[0]['tapped']);

        $employees = array_map(fn ($s) => $s['employee'] ?? $this->workerId, $specs);
        $overrides = array_map(fn ($s) => $s['fields'] + [
            'payload_version' => 'v3',
            'date' => self::DATE,
            'captured_at' => $this->ms($s['tapped']),
            'monotonic_timestamp' => 86_400_000 + ($this->ms($s['monotonic_at'] ?? $s['tapped']) - $first),
        ], $specs);

        return $this->buildBatch($employees, null, $overrides);
    }

    private function day(array $specs): TestResponse
    {
        return $this->sync($this->build($specs));
    }

    private function record(): Attendance
    {
        return Attendance::query()->where('employee_id', $this->workerId)->where('date', self::DATE)->sole();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse(self::DATE.' '.$time, 'Asia/Manila');
    }

    private function ms(string $time): int
    {
        return $this->at($time)->getTimestampMs();
    }
}
