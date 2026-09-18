<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Payroll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPayrollFixtures;
use Tests\TestCase;

/**
 * The Payroll Run workflow and holiday calendar (Phase 8 — UC-08, TC-06
 * steps 2-5): compute a cut-off, read it, open a payslip, approve what may be
 * paid. "Today" is Sun 06 Sep 2026, so 2026-09-A has just closed.
 */
class PayrollRunTest extends TestCase
{
    use BuildsPayrollFixtures;
    use RefreshDatabase;

    private Employee $hr;

    private Employee $ready;

    private Employee $blocked;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-06 09:00', 'Asia/Manila'));
        $this->setUpPayrollCrew();
        $this->hr = $this->loginUser('hr');

        $this->ready = $this->payrollWorker(600);
        $this->workedDays($this->ready, ['2026-08-24', '2026-08-25']);
        $this->overtime($this->ready, '2026-08-25', '16:00', '18:00');

        // A late-start credit HR has not reviewed yet.
        $this->blocked = $this->payrollWorker(600);
        $override = AuditLog::query()->create([
            'actor_id' => $this->crew->foreman_id,
            'action_type' => AuditLog::LATE_OVERRIDE,
            'crew_id' => $this->crew->crew_id,
            'subject_date' => '2026-08-24',
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_PENDING,
        ]);
        $this->signed($this->blocked, '2026-08-24', 'present', '07:00', ['override_flag' => 'shift_credit', 'override_audit_id' => $override->audit_id]);
    }

    public function test_hr_computes_a_run_and_reads_its_rows_and_totals(): void
    {
        $run = $this->compute()->assertOk()->json('data');

        $this->assertSame('draft', $run['status']);
        $this->assertSame('21 Aug – 05 Sep 2026', $run['label']);
        // Mon-Sat, less Ninoy Aquino Day and National Heroes Day.
        $this->assertSame(12, $run['working_days']);
        $this->assertSame(3, $run['summary']['employees']);
        $this->assertSame(1, $run['summary']['blocked']);
        // 600 + 600 + 2 x 75 x 1.25 for the ready worker.
        $this->assertSame(1387.5, $run['summary']['gross']);

        // Blocked rows first, with the reason.
        $this->assertSame($this->blocked->employee_id, $run['rows'][0]['employee']['employee_id']);
        $this->assertSame('blocked', $run['rows'][0]['readiness']);
        $this->assertStringContainsString('awaiting HR review', $run['rows'][0]['blocked_reasons'][0]['reason']);
    }

    public function test_the_payslip_shows_every_day_and_every_deduction(): void
    {
        $this->compute();

        $slip = $this->actingAs($this->hr, 'sanctum')
            ->getJson("/api/payroll/runs/2026-09-A/employees/{$this->ready->employee_id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(['regular', 'regular', 'overtime'], array_column($slip['lines'], 'kind'));
        $this->assertEqualsWithDelta(
            $slip['deductions'],
            array_sum($slip['deduction_lines']),
            0.001,
        );
        $this->assertNotEmpty($slip['tax_note']);
        $this->assertArrayHasKey('sss', $slip['employer_shares']);
    }

    /** TC-06: Draft until explicitly approved. Blocked rows are held, not paid. */
    public function test_approval_takes_the_ready_rows_and_holds_the_blocked_one(): void
    {
        $this->compute();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/payroll/runs/2026-09-A/approve')
            ->assertOk()
            ->assertJsonPath('approved', 2)
            ->assertJsonPath('data.status', 'partly_approved');

        $this->assertSame(Payroll::DRAFT, Payroll::query()->where('employee_id', $this->blocked->employee_id)->value('status'));

        $approved = Payroll::query()->where('employee_id', $this->ready->employee_id)->sole();
        $this->assertSame(Payroll::APPROVED, $approved->status);
        $this->assertSame($this->hr->employee_id, $approved->approved_by);

        $audit = AuditLog::query()->where('action_type', AuditLog::PAYROLL_APPROVED)->sole();
        $this->assertStringContainsString('1 blocked rows held', $audit->description);

        // Approved rows are final: new attendance for the period does not move them.
        $this->workedDays($this->ready, ['2026-08-26']);
        $this->compute();
        $this->assertSame('1387.50', $approved->fresh()->gross_pay);
    }

    public function test_approving_with_nothing_ready_is_refused(): void
    {
        $this->compute();
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/payroll/runs/2026-09-A/approve')->assertOk();

        $this->actingAs($this->hr, 'sanctum')->postJson('/api/payroll/runs/2026-09-A/approve')->assertUnprocessable();
    }

    public function test_the_run_list_shows_the_recent_cut_offs(): void
    {
        $this->compute();

        $runs = $this->actingAs($this->hr, 'sanctum')->getJson('/api/payroll/runs')->assertOk()->json('data');

        $this->assertCount(6, $runs);
        $this->assertSame(['2026-09-B', 'not_computed'], [$runs[0]['code'], $runs[0]['status']]);
        $this->assertSame(['2026-09-A', 'draft', 3], [$runs[1]['code'], $runs[1]['status'], $runs[1]['employees']]);
        $this->assertSame('2026-08-B', $runs[2]['code']);
    }

    public function test_a_period_that_has_not_started_cannot_be_computed(): void
    {
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/payroll/runs/2026-10-A/compute')->assertUnprocessable();
        $this->actingAs($this->hr, 'sanctum')->getJson('/api/payroll/runs/2026-09-C')->assertNotFound();
    }

    public function test_hr_runs_payroll_and_executives_only_read_it(): void
    {
        $this->compute();
        $executive = $this->loginUser('executive');

        $this->actingAs($executive, 'sanctum')->getJson('/api/payroll/runs/2026-09-A')->assertOk();
        $this->actingAs($executive, 'sanctum')->postJson('/api/payroll/runs/2026-09-A/compute')->assertForbidden();
        $this->actingAs($executive, 'sanctum')->postJson('/api/payroll/runs/2026-09-A/approve')->assertForbidden();

        foreach (['engineer', 'foreman', 'admin'] as $role) {
            $this->actingAs($this->loginUser($role), 'sanctum')->getJson('/api/payroll/runs')->assertForbidden();
        }
    }

    public function test_hr_keeps_the_holiday_calendar_and_draft_pay_follows_it(): void
    {
        $this->forget($this->anchor, '2026-09-01');
        $this->workedDays($this->ready, ['2026-09-01']);

        $before = $this->compute()->json('data.summary.gross');

        $id = $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/holidays', ['date' => '2026-09-01', 'name' => 'Local fiesta', 'type' => Holiday::SPECIAL])
            ->assertCreated()
            ->json('data.holiday_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/holidays', ['date' => '2026-09-01', 'name' => 'Local fiesta', 'type' => Holiday::SPECIAL])
            ->assertUnprocessable();

        // Tue 01 Sep worked on a special day: 8 x 75 x 1.30 instead of 8 x 75.
        $this->assertEqualsWithDelta($before + 180, $this->compute()->json('data.summary.gross'), 0.001);

        $this->actingAs($this->hr, 'sanctum')
            ->getJson('/api/holidays?year=2026')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->hr, 'sanctum')->deleteJson("/api/holidays/{$id}")->assertNoContent();
    }

    public function test_only_hr_changes_the_holiday_calendar(): void
    {
        $executive = $this->loginUser('executive');

        $this->actingAs($executive, 'sanctum')->getJson('/api/holidays')->assertOk();
        $this->actingAs($executive, 'sanctum')
            ->postJson('/api/holidays', ['date' => '2026-12-26', 'name' => 'Test', 'type' => Holiday::SPECIAL])
            ->assertForbidden();
        $this->actingAs($this->loginUser('foreman'), 'sanctum')->getJson('/api/holidays')->assertForbidden();
    }

    private function compute()
    {
        return $this->actingAs($this->hr, 'sanctum')->postJson('/api/payroll/runs/2026-09-A/compute');
    }
}
