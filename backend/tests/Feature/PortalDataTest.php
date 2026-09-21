<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Add-on B (FR-11, UC-11) — the worker portal's own-data reads (W2).
 *
 * No employee id parameter exists anywhere: each test seeds rows for two
 * workers and proves a worker sees exactly their own. Draft payroll rows
 * never appear by any path — index or detail — and a guessed run id reads as
 * 404 whether the run is someone else's, a draft, or missing entirely.
 */
class PortalDataTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ *
     *  /me/attendance
     * ------------------------------------------------------------------ */

    public function test_a_worker_reads_only_their_own_attendance_in_the_window(): void
    {
        $worker = $this->loginUser('worker');
        $other = $this->loginUser('worker');
        $crew = $this->crew($worker);

        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-01', 'present');
        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-05', 'late');
        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-20', 'present'); // outside the window
        $this->attendance($other->employee_id, $crew->crew_id, '2026-09-03', 'present');  // someone else's

        $response = $this->actingAs($worker, 'sanctum')
            ->getJson('/api/me/attendance?from=2026-09-01&to=2026-09-15')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $response
            ->assertJsonPath('data.0.date', '2026-09-01')
            ->assertJsonPath('data.0.status', 'present')
            ->assertJsonPath('data.0.time_in', '07:00')
            ->assertJsonPath('data.0.time_out', '17:00')
            ->assertJsonPath('data.0.time_out_source', 'Tapped on the device')
            ->assertJsonPath('data.0.review', 'Cleared')
            ->assertJsonPath('data.1.date', '2026-09-05')
            ->assertJsonPath('data.1.status', 'late');

        // Nothing secret may ride along: raw taps, sync state, flags and
        // review notes stay server-side.
        foreach (['sync_status', 'override_flag', 'monotonic_timestamp', 'captured_at', 'review_note'] as $key) {
            $response->assertJsonMissingPath("data.0.{$key}");
        }
    }

    public function test_time_out_source_and_review_state_are_plain_words(): void
    {
        $worker = $this->loginUser('worker');
        $foreman = $this->loginUser('foreman');
        $crew = $this->crew($foreman);

        // Shift close credits the time out.
        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-01', 'present', [
            'time_out_type' => Attendance::TIME_OUT_SHIFT_END,
        ]);

        // A foreman-stated time out under pending HR review.
        $pending = $this->audit(AuditLog::MANUAL_TIME_OUT, $foreman->employee_id, AuditLog::REVIEW_PENDING);
        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-02', 'present', [
            'time_out_type' => Attendance::TIME_OUT_MANUAL,
            'time_out_audit_id' => $pending->audit_id,
        ]);

        $response = $this->actingAs($worker, 'sanctum')
            ->getJson('/api/me/attendance?from=2026-09-01&to=2026-09-02')
            ->assertOk();

        $response
            ->assertJsonPath('data.0.time_out_source', 'Close shift credited a time out')
            ->assertJsonPath('data.0.review', 'Cleared')
            ->assertJsonPath('data.1.time_out_source', 'Stated by your foreman')
            ->assertJsonPath('data.1.review', 'Under HR review');
    }

    public function test_attendance_window_must_be_valid(): void
    {
        $worker = $this->loginUser('worker');
        $acting = $this->actingAs($worker, 'sanctum');

        $acting->getJson('/api/me/attendance')->assertUnprocessable();
        $acting->getJson('/api/me/attendance?from=2026-09-10')->assertUnprocessable();
        $acting->getJson('/api/me/attendance?from=09/10/2026&to=2026-09-10')->assertUnprocessable();
        $acting->getJson('/api/me/attendance?from=2026-09-10&to=2026-09-01')->assertUnprocessable();
        $acting->getJson('/api/me/attendance?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
    }

    /* ------------------------------------------------------------------ *
     *  /me/payslips
     * ------------------------------------------------------------------ */

    public function test_payslips_lists_only_the_workers_own_approved_runs(): void
    {
        $worker = $this->loginUser('worker');
        $other = $this->loginUser('worker');

        $approved = $this->payrollRun($worker->employee_id, Payroll::APPROVED, '2026-09-A', '2026-09-01', '2026-09-15');
        $this->payrollRun($worker->employee_id, Payroll::DRAFT, '2026-09-B', '2026-09-16', '2026-09-30'); // never appears
        $this->payrollRun($other->employee_id, Payroll::APPROVED, '2026-09-A', '2026-09-01', '2026-09-15'); // someone else's

        $this->actingAs($worker, 'sanctum')
            ->getJson('/api/me/payslips')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.run_id', $approved->payroll_id)
            ->assertJsonPath('data.0.run_code', '2026-09-A')
            ->assertJsonPath('data.0.gross_pay', 8000)
            ->assertJsonPath('data.0.deductions', 800)
            ->assertJsonPath('data.0.net_pay', 7200)
            ->assertJsonFragment(['period' => ['start' => '2026-09-01', 'end' => '2026-09-15']]);

        // The list carries totals, not the breakdown; that ships only on the
        // detail read.
        $this->actingAs($worker, 'sanctum')
            ->getJson('/api/me/payslips')
            ->assertJsonMissingPath('data.0.detail');
    }

    public function test_payslip_detail_is_itemised_for_the_workers_approved_run(): void
    {
        $worker = $this->loginUser('worker');
        $approved = $this->payrollRun($worker->employee_id, Payroll::APPROVED, '2026-09-A', '2026-09-01', '2026-09-15');

        $this->actingAs($worker, 'sanctum')
            ->getJson('/api/me/payslips/'.$approved->payroll_id)
            ->assertOk()
            ->assertJsonPath('data.run_code', '2026-09-A')
            ->assertJsonPath('data.net_pay', 7200)
            ->assertJsonPath('data.detail.basic_pay', 8000)
            ->assertJsonPath('data.detail.premium_pay', 0)
            ->assertJsonPath('data.detail.deduction_lines.sss', 400)
            ->assertJsonPath('data.detail.deduction_lines.philhealth', 200)
            ->assertJsonPath('data.detail.deduction_lines.pagibig', 100)
            ->assertJsonPath('data.detail.deduction_lines.withholding_tax', 100)
            ->assertJsonPath('data.detail.employer_shares.sss', 800)
            ->assertJsonPath('data.detail.hours.regular', 80);
    }

    public function test_payslip_detail_refuses_draft_other_workers_and_guessed_runs(): void
    {
        $worker = $this->loginUser('worker');
        $other = $this->loginUser('worker');

        $draft = $this->payrollRun($worker->employee_id, Payroll::DRAFT, '2026-09-B', '2026-09-16', '2026-09-30');
        $theirs = $this->payrollRun($other->employee_id, Payroll::APPROVED, '2026-09-A', '2026-09-01', '2026-09-15');

        $acting = $this->actingAs($worker, 'sanctum');

        // Their own draft is not available, full stop.
        $acting->getJson('/api/me/payslips/'.$draft->payroll_id)->assertNotFound();
        // Someone else's approved run is indistinguishable from missing.
        $acting->getJson('/api/me/payslips/'.$theirs->payroll_id)->assertNotFound();
        // And a blind guess is the same 404.
        $acting->getJson('/api/me/payslips/999999')->assertNotFound();
        // Read back the other way: the run's owner cannot read it either.
        $this->actingAs($other, 'sanctum')
            ->getJson('/api/me/payslips/'.$draft->payroll_id)
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /** A deployed crew on the employee's site, so attendance rows have a home. */
    private function crew(Employee $employee): Crew
    {
        return Crew::factory()->create([
            'site_id' => $employee->site_id,
            'foreman_id' => null,
            'status' => 'deployed',
        ]);
    }

    /** One attendance row: a 07:00–17:00 Manila day unless overridden. */
    private function attendance(int $employeeId, int $crewId, string $date, string $status, array $overrides = []): Attendance
    {
        $day = Carbon::parse($date, config('attendance.timezone', 'Asia/Manila'));

        return Attendance::create(array_merge([
            'employee_id' => $employeeId,
            'crew_id' => $crewId,
            'date' => $day->toDateString(),
            'status' => $status,
            'time_in' => $day->copy()->setTime(7, 0)->setTimezone('UTC'),
            'captured_at' => $day->copy()->setTime(7, 0)->setTimezone('UTC'),
            'time_out' => $day->copy()->setTime(17, 0)->setTimezone('UTC'),
            'sync_status' => 'synced',
        ], $overrides));
    }

    private function audit(string $action, int $actorId, string $review): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actorId,
            'action_type' => $action,
            'description' => 'Test audit row for '.$action,
            'timestamp' => now(),
            'review_status' => $review,
        ]);
    }

    /** One payroll row with a fully itemised detail, approved or draft. */
    private function payrollRun(int $employeeId, string $status, string $code, string $start, string $end): Payroll
    {
        $payroll = Payroll::create([
            'employee_id' => $employeeId,
            'run_code' => $code,
            'pay_period_start' => $start,
            'pay_period_end' => $end,
            'gross_pay' => 8000.00,
            'net_pay' => 7200.00,
            'status' => $status,
            'approved_at' => $status === Payroll::APPROVED ? now() : null,
        ]);

        PayrollDetail::create([
            'payroll_id' => $payroll->payroll_id,
            'regular_hours' => 80.00,
            'basic_pay' => 8000.00,
            'premium_pay' => 0.00,
            'sss_employee' => 400.00,
            'philhealth_employee' => 200.00,
            'pagibig_employee' => 100.00,
            'withholding_tax' => 100.00,
            'other_deductions' => 0.00,
            'deductions' => 800.00,
            'sss_employer' => 800.00,
            'philhealth_employer' => 200.00,
            'pagibig_employer' => 100.00,
            'tax_note' => 'Withheld per BIR table.',
            'readiness' => PayrollDetail::READY,
            'blocked_reasons' => [],
            'breakdown' => ['hourly_rate' => 100.00, 'lines' => [], 'warnings' => []],
        ]);

        return $payroll;
    }
}
