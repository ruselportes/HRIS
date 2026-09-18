<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Services\Payroll\PayPeriod;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPayrollFixtures;
use Tests\TestCase;

/**
 * The payroll engine end to end, from signed roll call to payslip.
 *
 * Period 2026-09-A: Fri 21 Aug - Sat 05 Sep 2026. Fri 21 Aug is Ninoy Aquino
 * Day (special), Mon 31 Aug National Heroes Day (regular). Sundays are the
 * rest day.
 */
class PayrollEngineTest extends TestCase
{
    use BuildsPayrollFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-06 09:00', 'Asia/Manila'));
        $this->setUpPayrollCrew();
    }

    /**
     * STD TC-06 (revised with the team): one worker at ₱600/day (₱75/h), each
     * premium on a real day, checked against a hand calculation.
     *
     *   Fri 21 Aug  special day, 8 h            8 x 75 x 1.30          =   780.00
     *   Mon 24 Aug  ordinary, 8 h               8 x 75                 =   600.00
     *   Tue 25 Aug  ordinary 8 h + OT 16-18     600 + 2 x 75 x 1.25    =   787.50
     *   Wed 26 Aug  ordinary 8 h + OT 20-24     600 + 2 x 75 x 1.25
     *               (22-24 at night)                + 2 x 75 x 1.375   =   993.75
     *   Sun 30 Aug  rest day, 8 h               8 x 75 x 1.30          =   780.00
     *   Mon 31 Aug  regular holiday, 8 h        8 x 75 x 2.00          = 1,200.00
     *                                                         gross      5,141.25
     */
    public function test_tc06_every_premium_matches_the_hand_calculation(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-21', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-30', '2026-08-31']);
        $this->overtime($worker, '2026-08-25', '16:00', '18:00');
        $this->overtime($worker, '2026-08-26', '20:00', '00:00');

        $payroll = $this->payrollFor($worker);
        $detail = $payroll->detail;

        $this->assertSame(
            ['regular' => 24.0, 'overtime' => 6.0, 'night' => 2.0, 'rest_day' => 8.0, 'holiday' => 16.0],
            [
                'regular' => (float) $detail->regular_hours,
                'overtime' => (float) $detail->overtime_hours,
                'night' => (float) $detail->night_diff_hours,
                'rest_day' => (float) $detail->rest_day_hours,
                'holiday' => (float) $detail->holiday_hours,
            ],
        );

        $this->assertSame('5141.25', $payroll->gross_pay);

        // Net is gross less the itemised deductions, and nothing else.
        $itemised = $detail->sss_employee + $detail->philhealth_employee + $detail->pagibig_employee + $detail->withholding_tax;
        $this->assertEqualsWithDelta((float) $detail->deductions, $itemised, 0.001);
        $this->assertEqualsWithDelta(5141.25 - $itemised, (float) $payroll->net_pay, 0.001);

        // Draft until explicitly approved.
        $this->assertSame(Payroll::DRAFT, $payroll->status);
        $this->assertSame('2026-09-A', $payroll->run_code);
    }

    public function test_every_amount_is_traceable_to_a_day(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-26']);
        $this->overtime($worker, '2026-08-26', '20:00', '00:00');

        $lines = collect($this->payrollFor($worker)->detail->breakdown['lines']);

        $this->assertSame(
            [['regular', 600.0], ['overtime', 187.5], ['overtime_night', 206.25]],
            $lines->map(fn ($l) => [$l['kind'], (float) $l['amount']])->all(),
        );
    }

    public function test_a_late_worker_is_paid_from_arrival(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-24'], 'late', '08:00');

        // 08:00-16:00 less the meal hour: 7 h x ₱75.
        $this->assertSame('525.00', $this->payrollFor($worker)->gross_pay);
    }

    /** Art. 94: paid when unworked, to someone present on the working day before. */
    public function test_an_unworked_regular_holiday_is_paid_to_those_present_the_working_day_before(): void
    {
        $entitled = $this->payrollWorker(600);
        // Sat 29 Aug worked; Sun 30 is the rest day; absent on the holiday itself.
        $this->workedDays($entitled, ['2026-08-29']);
        $this->absentDays($entitled, ['2026-08-31']);

        $notEntitled = $this->payrollWorker(600);
        $this->absentDays($notEntitled, ['2026-08-29', '2026-08-31']);

        $payrolls = $this->runPayroll()->keyBy('employee_id');

        // ₱600 for Saturday + ₱600 holiday pay.
        $this->assertSame('1200.00', $payrolls[$entitled->employee_id]->gross_pay);
        $this->assertSame('8.00', $payrolls[$entitled->employee_id]->detail->unworked_holiday_hours);
        $this->assertSame('0.00', $payrolls[$notEntitled->employee_id]->gross_pay);
    }

    public function test_an_unworked_special_day_is_unpaid(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-20']);
        $this->absentDays($worker, ['2026-08-21']);

        $this->assertSame('0.00', $this->payrollFor($worker)->gross_pay);
    }

    public function test_overtime_without_roll_call_is_not_paid_and_says_why(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-24']);
        $this->overtime($worker, '2026-08-27', '16:00', '18:00');

        $detail = $this->payrollFor($worker)->detail;

        $this->assertSame('600.00', $detail->payroll->gross_pay);
        $this->assertStringContainsString('2026-08-27', $detail->breakdown['warnings'][0]);
    }

    public function test_attendance_hr_has_not_cleared_holds_the_row(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-24']);

        $override = AuditLog::query()->create([
            'actor_id' => $this->crew->foreman_id,
            'action_type' => AuditLog::LATE_OVERRIDE,
            'crew_id' => $this->crew->crew_id,
            'subject_date' => '2026-08-25',
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_PENDING,
        ]);
        $this->signed($worker, '2026-08-25', 'present', '07:00', ['override_flag' => 'shift_credit', 'override_audit_id' => $override->audit_id]);

        $detail = $this->payrollFor($worker)->detail;

        $this->assertSame(PayrollDetail::BLOCKED, $detail->readiness);
        $this->assertSame('2026-08-25', $detail->blocked_reasons[0]['date']);
        $this->assertStringContainsString('awaiting HR review', $detail->blocked_reasons[0]['reason']);
        // Only the cleared day is counted meanwhile.
        $this->assertSame('600.00', $detail->payroll->gross_pay);
    }

    public function test_a_recovered_day_is_paid_and_the_row_says_so(): void
    {
        $worker = $this->payrollWorker(600);
        $case = AuditLog::query()->create([
            'actor_id' => $this->loginUser('engineer')->employee_id,
            'action_type' => AuditLog::RETROACTIVE_RECOVERY,
            'crew_id' => $this->crew->crew_id,
            'subject_date' => '2026-08-24',
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_APPROVED,
        ]);
        Attendance::query()->create([
            'employee_id' => $worker->employee_id,
            'crew_id' => $this->crew->crew_id,
            'date' => '2026-08-24',
            'status' => 'present',
            'time_in' => Carbon::parse('2026-08-24 07:00', 'Asia/Manila')->utc(),
            'sync_status' => Attendance::RECONSTRUCTED,
            'override_flag' => Attendance::RECONSTRUCTED,
            'override_audit_id' => $case->audit_id,
        ]);

        $payroll = $this->payrollFor($worker);

        $this->assertSame(PayrollDetail::RECOVERED, $payroll->detail->readiness);
        $this->assertSame('600.00', $payroll->gross_pay);
    }

    public function test_a_crew_day_awaiting_recovery_holds_the_row(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-24', '2026-08-26']);
        // Nobody on the crew has roll call for Tue 25 Aug: an open recovery gap.
        $this->forget(null, '2026-08-25');

        $detail = $this->payrollFor($worker)->detail;

        $this->assertSame(PayrollDetail::BLOCKED, $detail->readiness);
        $this->assertSame(['2026-08-25'], array_column($detail->blocked_reasons, 'date'));
    }

    /** Re-running an old period uses the rates that were in force then. */
    public function test_a_later_rate_change_does_not_rewrite_earlier_days(): void
    {
        $sets = config('payroll.premiums');
        $raised = $sets[0];
        $raised['from'] = '2026-08-27';
        $raised['day']['rest_day'] = 1.50;
        config(['payroll.premiums' => [...$sets, $raised]]);

        $worker = $this->payrollWorker(600);
        // Sun 23 Aug under the old rate, Sun 30 Aug under the new one.
        $this->workedDays($worker, ['2026-08-23', '2026-08-30']);

        // 8 x 75 x 1.30 + 8 x 75 x 1.50.
        $this->assertSame('1680.00', $this->payrollFor($worker)->gross_pay);
    }

    public function test_recomputing_replaces_the_draft_but_never_an_approved_row(): void
    {
        $worker = $this->payrollWorker(600);
        $this->workedDays($worker, ['2026-08-24']);
        $this->runPayroll();

        $this->forget($this->anchor, '2026-08-25');
        $this->workedDays($worker, ['2026-08-25']);
        $this->assertSame('1200.00', $this->payrollFor($worker)->gross_pay);
        $this->assertSame(1, Payroll::query()->where('employee_id', $worker->employee_id)->count());

        Payroll::query()->update(['status' => 'approved']);
        $this->forget($this->anchor, '2026-08-26');
        $this->workedDays($worker, ['2026-08-26']);
        $this->runPayroll();

        $this->assertSame('1200.00', Payroll::query()->where('employee_id', $worker->employee_id)->sole()->gross_pay);
    }

    private function runPayroll()
    {
        return app(PayrollEngine::class)->run(PayPeriod::fromCode('2026-09-A'));
    }

    private function payrollFor(Employee $worker): Payroll
    {
        return $this->runPayroll()->firstWhere('employee_id', $worker->employee_id);
    }
}
