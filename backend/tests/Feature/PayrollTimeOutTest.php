<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Services\Payroll\PayPeriod;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPayrollFixtures;
use Tests\TestCase;

/**
 * Payroll's use of the time-out (time-out capture, slice 4).
 *
 * Period 2026-09-A: Fri 21 Aug - Sat 05 Sep 2026. Time-outs count as tracked
 * from Tue 01 Sep here, so days before it show what payroll did before phones
 * captured them. One worker at ₱600/day (₱75/h); the shift is 07:00-16:00
 * with the meal hour unpaid.
 */
class PayrollTimeOutTest extends TestCase
{
    use BuildsPayrollFixtures;
    use RefreshDatabase;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-06 09:00', 'Asia/Manila'));
        config(['attendance.time_out_tracked_from' => '2026-09-01']);
        $this->setUpPayrollCrew();
        $this->worker = $this->payrollWorker(600);
    }

    public function test_leaving_early_is_paid_to_the_time_out(): void
    {
        $this->timedOut('2026-09-01', '14:00');

        $detail = $this->detail();

        // 07:00-14:00 less the meal hour: 6 h x ₱75.
        $this->assertSame('450.00', $detail->payroll->gross_pay);
        $this->assertSame('14:00', $detail->breakdown['lines'][0]['time_out']);
        $this->assertSame([], $detail->breakdown['warnings']);
    }

    public function test_a_time_out_after_shift_end_is_still_eight_regular_hours(): void
    {
        $this->timedOut('2026-09-01', '18:30');

        $detail = $this->detail();

        $this->assertSame('600.00', $detail->payroll->gross_pay);
        $this->assertArrayNotHasKey('time_out', $detail->breakdown['lines'][0]);
    }

    public function test_close_shift_pays_the_full_day(): void
    {
        $this->timedOut('2026-09-01', '16:00', Attendance::TIME_OUT_SHIFT_END);

        $this->assertSame('600.00', $this->detail()->payroll->gross_pay);
    }

    public function test_a_late_worker_who_left_early_is_paid_between_the_two(): void
    {
        $this->timedOut('2026-09-01', '15:00', status: 'late', timeIn: '08:00');

        // 08:00-15:00 less the meal hour: 6 h.
        $this->assertSame('450.00', $this->detail()->payroll->gross_pay);
    }

    public function test_overtime_is_paid_only_until_the_worker_was_timed_out(): void
    {
        $this->timedOut('2026-09-01', '17:00');
        $this->overtime($this->worker, '2026-09-01', '16:00', '19:00');

        $detail = $this->detail();

        // ₱600 + 1 h x ₱75 x 1.25, not the 3 h approved.
        $this->assertSame('693.75', $detail->payroll->gross_pay);
        $this->assertSame('1.00', $detail->overtime_hours);
        $this->assertStringContainsString('paid only to 17:00', $detail->breakdown['warnings'][0]);
    }

    public function test_overtime_is_not_paid_to_a_worker_who_left_before_it_began(): void
    {
        $this->timedOut('2026-09-01', '14:00');
        $this->overtime($this->worker, '2026-09-01', '16:00', '18:00');

        $detail = $this->detail();

        // Undertime at 14:00 and no overtime: Art. 88, nothing offsets the other.
        $this->assertSame('450.00', $detail->payroll->gross_pay);
        $this->assertStringContainsString('before it began', $detail->breakdown['warnings'][0]);
    }

    /** Close shift says nobody left early, not when anyone left after. */
    public function test_close_shift_never_cuts_approved_overtime(): void
    {
        $this->timedOut('2026-09-01', '16:00', Attendance::TIME_OUT_SHIFT_END);
        $this->overtime($this->worker, '2026-09-01', '16:00', '18:00');

        // ₱600 + 2 h x ₱75 x 1.25.
        $this->assertSame('787.50', $this->detail()->payroll->gross_pay);
    }

    /** Art. 88: undertime one day is not made up by overtime on another. */
    public function test_undertime_is_not_offset_by_overtime_on_another_day(): void
    {
        $this->timedOut('2026-09-01', '14:00');
        $this->timedOut('2026-09-02', '18:00');
        $this->overtime($this->worker, '2026-09-02', '16:00', '18:00');

        $detail = $this->detail();

        // 6 h on the 1st; 8 h + 2 h OT at 1.25 on the 2nd.
        $this->assertSame('1237.50', $detail->payroll->gross_pay);
        $this->assertSame('14.00', $detail->regular_hours);
        $this->assertSame('2.00', $detail->overtime_hours);
    }

    public function test_a_worked_day_with_no_time_out_is_paid_to_shift_end_with_a_warning(): void
    {
        $this->workedDays($this->worker, ['2026-09-01']);

        $detail = $this->detail();

        $this->assertSame('600.00', $detail->payroll->gross_pay);
        $this->assertSame(PayrollDetail::READY, $detail->readiness);
        $this->assertSame(['No time-out recorded on 2026-09-01: paid to the end of the shift.'], $detail->breakdown['warnings']);
    }

    public function test_no_warning_before_phones_captured_time_outs(): void
    {
        $this->workedDays($this->worker, ['2026-08-31', '2026-08-28']);

        $this->assertSame([], $this->detail()->breakdown['warnings']);
    }

    public function test_a_stated_time_out_holds_the_row_until_hr_decides(): void
    {
        $event = $this->statedTimeOut('2026-09-01', '14:00', enteredAt: '18:30');

        $detail = $this->detail();
        $this->assertSame(PayrollDetail::BLOCKED, $detail->readiness);
        $this->assertSame('Foreman-set time-out awaiting HR review', $detail->blocked_reasons[0]['reason']);

        $event->update(['review_status' => AuditLog::REVIEW_APPROVED]);

        $detail = $this->detail();
        $this->assertSame(PayrollDetail::READY, $detail->readiness);
        $this->assertSame('450.00', $detail->payroll->gross_pay);
    }

    /** As a rejected arrival credit falls back to the real tap. */
    public function test_a_rejected_stated_time_out_is_paid_to_when_it_was_entered(): void
    {
        $event = $this->statedTimeOut('2026-09-01', '14:00', enteredAt: '18:30');
        $event->update(['review_status' => AuditLog::REVIEW_REJECTED]);
        $this->overtime($this->worker, '2026-09-01', '16:00', '19:00');

        $detail = $this->detail();

        // The full day, and overtime only to 18:30: 600 + 2.5 h x 75 x 1.25.
        $this->assertSame('834.38', $detail->payroll->gross_pay);
        $this->assertStringContainsString('paid only to 18:30', $detail->breakdown['warnings'][0]);
    }

    /** Recovery records arrival only, so a rebuilt day has no time-out to miss. */
    public function test_a_reconstructed_day_is_not_warned_about(): void
    {
        $case = AuditLog::query()->create([
            'actor_id' => $this->crew->foreman_id,
            'action_type' => AuditLog::RETROACTIVE_RECOVERY,
            'crew_id' => $this->crew->crew_id,
            'subject_date' => '2026-09-01',
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_APPROVED,
        ]);
        Attendance::query()->create([
            'employee_id' => $this->worker->employee_id,
            'crew_id' => $this->crew->crew_id,
            'date' => '2026-09-01',
            'status' => 'present',
            'time_in' => Carbon::parse('2026-09-01 07:00', 'Asia/Manila')->utc(),
            'captured_at' => Carbon::parse('2026-09-03 10:00', 'Asia/Manila')->utc(),
            'sync_status' => Attendance::RECONSTRUCTED,
            'override_flag' => Attendance::RECONSTRUCTED,
            'override_audit_id' => $case->audit_id,
        ]);

        $this->assertSame([], $this->detail()->breakdown['warnings']);
    }

    /* --------------------------------------------------------------------- */

    private function timedOut(
        string $date,
        string $out,
        ?string $type = null,
        string $status = 'present',
        string $timeIn = '06:55',
    ): Attendance {
        return $this->signed($this->worker, $date, $status, $timeIn, [
            'time_out' => Carbon::parse("{$date} {$out}", 'Asia/Manila')->utc(),
            'time_out_type' => $type,
            'time_out_captured_at' => Carbon::parse("{$date} {$out}", 'Asia/Manila')->utc(),
        ]);
    }

    private function statedTimeOut(string $date, string $out, string $enteredAt): AuditLog
    {
        $event = AuditLog::query()->create([
            'actor_id' => $this->crew->foreman_id,
            'action_type' => AuditLog::MANUAL_TIME_OUT,
            'crew_id' => $this->crew->crew_id,
            'subject_date' => $date,
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_PENDING,
        ]);

        $this->signed($this->worker, $date, 'present', '06:55', [
            'time_out' => Carbon::parse("{$date} {$out}", 'Asia/Manila')->utc(),
            'time_out_type' => Attendance::TIME_OUT_MANUAL,
            'time_out_captured_at' => Carbon::parse("{$date} {$enteredAt}", 'Asia/Manila')->utc(),
            'time_out_audit_id' => $event->audit_id,
        ]);

        return $event;
    }

    private function detail(): PayrollDetail
    {
        return app(PayrollEngine::class)
            ->run(PayPeriod::fromCode('2026-09-A'))
            ->firstWhere('employee_id', $this->worker->employee_id)
            ->detail;
    }
}
