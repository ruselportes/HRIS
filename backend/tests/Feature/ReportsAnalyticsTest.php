<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * Reports & Analytics (Phase 9 — UC-09, FR-09), built against the Executive
 * Dashboard prototype. Locks in: the scorecard formula and bands per site,
 * approved-only labour cost sliced by payslip line date, attendance/leave
 * reporting, the exclusive day boundary, the site filter, the validated query
 * contract, and the nav `reports` access matrix (hr/engineer/executive).
 */
class ReportsAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    private const TODAY = '2026-09-17';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 08:00:00', config('attendance.timezone')));
        $this->setUpSignedDevice();
    }

    public function test_clean_book_yields_a_perfect_score(): void
    {
        $this->attendance($this->worker()->employee_id, 'present');

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.window.from', '2026-08-19')
            ->assertJsonPath('data.window.to', self::TODAY)
            ->assertJsonPath('data.kpis.headcount', 3)
            ->assertJsonPath('data.kpis.sites_active', 1)
            ->assertJsonPath('data.kpis.attendance_rate', 100)
            ->assertJsonPath('data.kpis.absence_rate', 0)
            ->assertJsonPath('data.score.value', 100)
            ->assertJsonPath('data.score.band', 'good')
            ->assertJsonPath('data.attendance.present', 1)
            ->assertJsonPath('data.attendance.absent', 0)
            ->assertJsonPath('data.leaves.requests', 0)
            ->assertJsonPath('data.labour_cost.total', 0)
            ->assertJsonPath('data.overtime.requests', 0)
            ->assertJsonCount(1, 'data.sites')
            ->assertJsonPath('data.sites.0.site_name', $this->site()->site_name)
            ->assertJsonPath('data.sites.0.attendance.present', 1)
            ->assertJsonPath('data.sites.0.score.value', 100)
            ->assertJsonCount(0, 'data.flagged_audits');
    }

    public function test_sites_are_scored_independently_from_their_own_components(): void
    {
        $siteA = $this->site();
        $siteB = $this->site('Site 02 — Other');

        Employee::factory()->create([
            // Expired certification; the penalty belongs to its home site only.
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $siteA->site_id,
            'certification' => [['name' => 'Heavy Equipment', 'expires_at' => '2020-01-01']],
        ]);

        Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $siteB->site_id,
        ]);

        // One override (actor = foreman at A) and one incident (crew at A).
        $this->seedAudit(AuditLog::LATE_OVERRIDE, now());
        $this->seedAudit(AuditLog::ATTENDANCE_VERIFICATION_FAILED, now());

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonCount(2, 'data.sites')
            ->assertJsonPath('data.kpis.headcount', 4)
            // Aggregate is the headcount-weighted average of the per-site
            // scores: site A (92, 3 homed) and site B (100, 1 homed) →
            // (92·3 + 100·1) / 4 = 94. The deduped component sums would have
            // driven a big headcount's score to zero; FR-09 records the
            // weighted basis.
            ->assertJsonPath('data.score.value', 94)
            ->assertJsonPath('data.score.band', 'good')
            ->assertJsonPath('data.score.basis', 'headcount-weighted average of per-site scores');

        $response = $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->json('data.sites');

        $rowA = collect($response)->firstWhere('site_id', $siteA->site_id);
        $rowB = collect($response)->firstWhere('site_id', $siteB->site_id);

        $this->assertSame(92, $rowA['score']['value']);
        $this->assertSame('good', $rowA['score']['band']);
        $this->assertSame(1, $rowA['certifications_expired']);
        $this->assertSame(1, $rowA['overrides']);
        $this->assertSame(1, $rowA['integrity_incidents']);

        $this->assertSame(100, $rowB['score']['value']);
        $this->assertSame('good', $rowB['score']['band']);
        $this->assertSame(0, $rowB['certifications_expired']);
        $this->assertSame(0, $rowB['overrides']);
        $this->assertSame(0, $rowB['integrity_incidents']);
    }

    public function test_fair_and_watch_bands_fall_at_the_set_thresholds(): void
    {
        // 3 expired certs (6) + 5 overrides (5) + 1 incident (5) = 16 pts → 84 → fair.
        foreach (range(1, 3) as $i) {
            Employee::factory()->create([
                'role_id' => $this->role('worker')->role_id,
                'site_id' => $this->site()->site_id,
                'certification' => [['name' => 'TESDA', 'expires_at' => '2021-01-01']],
            ]);
        }

        foreach (range(1, 5) as $i) {
            $this->seedAudit(AuditLog::LATE_OVERRIDE, now()->subDays($i));
        }
        $this->seedAudit(AuditLog::ATTENDANCE_VERIFICATION_FAILED, now());

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.score.value', 84)
            ->assertJsonPath('data.score.band', 'fair')
            ->assertJsonPath('data.sites.0.score.band', 'fair');

        // 3 more overrides (3) + a second incident (5) → 24 pts → 76 → watch.
        foreach (range(6, 8) as $i) {
            $this->seedAudit(AuditLog::MANUAL_TIME_OUT, now()->subDays($i));
        }
        $this->seedAudit(AuditLog::ATTENDANCE_VERIFICATION_FAILED, now()->subDays(10));

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.score.value', 76)
            ->assertJsonPath('data.score.band', 'watch')
            ->assertJsonPath('data.sites.0.score.band', 'watch');
    }

    public function test_attendance_leave_and_overtime_are_reported(): void
    {
        $workers = [$this->worker(), $this->worker(), $this->worker()];
        $this->attendance($workers[0]->employee_id, 'present');
        $this->attendance($workers[1]->employee_id, 'late');
        $this->attendance($workers[2]->employee_id, 'absent');

        OvertimeRequest::create([
            'employee_id' => $workers[0]->employee_id,
            'filed_by' => $this->foreman->employee_id,
            'ot_date' => '2026-09-12',
            'start_time' => '18:00',
            'end_time' => '21:00',
            'hours_requested' => 1.5,
            'status' => OvertimeRequest::APPROVED,
            'approved_at' => now(),
        ]);

        LeaveRequest::create([
            'employee_id' => $workers[1]->employee_id,
            'filed_by' => $this->foreman->employee_id,
            'leave_type' => 'vacation',
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-12',
            'status' => LeaveRequest::APPROVED,
            'approved_at' => now(),
        ]);

        LeaveRequest::create([
            'employee_id' => $workers[2]->employee_id,
            'filed_by' => $this->foreman->employee_id,
            'leave_type' => 'sick',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-03',
            'status' => LeaveRequest::PENDING,
        ]);

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.attendance.present', 1)
            ->assertJsonPath('data.attendance.late', 1)
            ->assertJsonPath('data.attendance.absent', 1)
            ->assertJsonPath('data.kpis.attendance_rate', 66.7)
            ->assertJsonPath('data.kpis.absence_rate', 33.3)
            ->assertJsonPath('data.kpis.late_rate', 50)
            ->assertJsonPath('data.overtime.requests', 1)
            ->assertJsonPath('data.overtime.hours', 1.5)
            ->assertJsonPath('data.leaves.requests', 1)
            ->assertJsonPath('data.leaves.days', 3)
            ->assertJsonPath('data.sites.0.attendance_rate', 66.7)
            ->assertJsonPath('data.sites.0.leaves.days', 3);
    }

    public function test_labour_cost_counts_only_approved_rows_by_line_date(): void
    {
        $worker = $this->worker()->refresh();

        $approved = Payroll::create([
            'employee_id' => $worker->employee_id,
            'run_code' => '2026-09-A',
            'pay_period_start' => '2026-09-01',
            'pay_period_end' => '2026-09-15',
            'gross_pay' => 1250.00,
            'net_pay' => 1250.00,
            'status' => Payroll::APPROVED,
        ]);

        PayrollDetail::create([
            'payroll_id' => $approved->payroll_id,
            'readiness' => PayrollDetail::READY,
            'breakdown' => ['lines' => [
                ['date' => '2026-09-10', 'kind' => 'regular', 'hours' => 8, 'multiplier' => 1.0, 'amount' => 1000.00],
                ['date' => '2026-09-11', 'kind' => 'overtime', 'hours' => 2, 'multiplier' => 1.25, 'amount' => 250.00],
                // Outside the window: must not count.
                ['date' => '2026-08-01', 'kind' => 'regular', 'hours' => 8, 'multiplier' => 1.0, 'amount' => 9999.00],
            ]],
        ]);

        // A draft run's line inside the window must never be paid against.
        $draftWorker = $this->worker();
        $draft = Payroll::create([
            'employee_id' => $draftWorker->employee_id,
            'run_code' => '2026-09-A',
            'pay_period_start' => '2026-09-01',
            'pay_period_end' => '2026-09-15',
            'gross_pay' => 5000.00,
            'net_pay' => 5000.00,
            'status' => Payroll::DRAFT,
        ]);

        PayrollDetail::create([
            'payroll_id' => $draft->payroll_id,
            'readiness' => PayrollDetail::READY,
            'breakdown' => ['lines' => [
                ['date' => '2026-09-12', 'kind' => 'regular', 'hours' => 8, 'multiplier' => 1.0, 'amount' => 5000.00],
            ]],
        ]);

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.labour_cost.total', 1250)
            ->assertJsonPath('data.labour_cost.regular', 1000)
            ->assertJsonPath('data.labour_cost.overtime', 250)
            ->assertJsonPath('data.kpis.overtime_share', 20)
            ->assertJsonPath('data.sites.0.labour_cost.total', 1250)
            ->assertJsonPath('data.sites.0.labour_cost.overtime', 250);
    }

    public function test_the_window_boundary_is_exclusive_at_the_day_after_to(): void
    {
        $this->seedAudit(AuditLog::LATE_OVERRIDE, Carbon::parse('2026-08-19 00:00:00', config('attendance.timezone')));
        $this->seedAudit(AuditLog::MANUAL_TIME_OVERRIDE, Carbon::parse('2026-09-18 00:00:00', config('attendance.timezone')));

        $this->seedAudit(AuditLog::ATTENDANCE_VERIFICATION_FAILED, Carbon::parse('2026-09-12 08:00:00', config('attendance.timezone')));
        $this->seedAudit(AuditLog::ATTENDANCE_VERIFICATION_FAILED, Carbon::parse('2026-09-18 00:00:00', config('attendance.timezone')));

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.audit.overrides', 1)
            ->assertJsonPath('data.audit.integrity_incidents', 1)
            ->assertJsonPath('data.score.value', 94);
    }

    public function test_site_filter_scopes_every_dataset(): void
    {
        $siteB = $this->site('Site 02 — Other');
        $crewB = Crew::factory()->create([
            'site_id' => $siteB->site_id,
            'foreman_id' => $this->foreman->employee_id,
            'status' => 'deployed',
        ]);
        $workerB = Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $siteB->site_id,
        ]);

        $this->attendance($workerB->employee_id, 'present', $crewB->crew_id);
        // Activity on the unfiltered site that must not leak through.
        $this->seedAudit(AuditLog::LATE_OVERRIDE, now());

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview?site_id='.$siteB->site_id)
            ->assertOk()
            ->assertJsonPath('data.site.site_id', $siteB->site_id)
            ->assertJsonPath('data.kpis.headcount', 1)
            ->assertJsonPath('data.kpis.sites_active', 1)
            ->assertJsonPath('data.kpis.attendance_rate', 100)
            ->assertJsonCount(1, 'data.sites')
            ->assertJsonPath('data.sites.0.site_id', $siteB->site_id)
            ->assertJsonPath('data.sites.0.attendance.present', 1)
            ->assertJsonPath('data.audit.overrides', 0)
            ->assertJsonCount(0, 'data.flagged_audits');
    }

    public function test_validation_rejects_bad_filters(): void
    {
        $executive = $this->loginUser('executive');

        foreach ([
            // Burst 1 (the pre-existing list): bad or partial dates, ranges,
            // page sizes, and action/site filters.
            'from=garbage',
            'from=2026-09-17&to=2026-09-01',
            'from=2025-01-01&to=2026-12-31',
            'per_page=0',
            'per_page=100000',
            'action=NOPE',
            'from=2026-09-01',
            'to=2026-09-17',
            'site_id=999999',
            'page=0',
            // Burst 2 (the review's finds): a malformed date that accompanies a
            // good one used to 500 — the after() hook parsed it again — and
            // `date` accepted m/d/Y, whose raw strings then leaked into SQL
            // string comparisons.
            'from=garbage&to=2026-09-10',
            'from=09/01/2026&to=09/10/2026',
        ] as $query) {
            $this->actingAs($executive, 'sanctum')
                ->getJson('/api/reports/overview?'.$query)
                ->assertStatus(422);
        }

        $this->actingAs($executive, 'sanctum')
            ->getJson('/api/reports/overview?from=2026-09-01&to=2026-09-17')
            ->assertOk();
    }

    public function test_audit_feed_filters_paginates_and_matches_the_nav_matrix(): void
    {
        $this->seedAudit(AuditLog::LATE_OVERRIDE, now());
        $this->seedAudit(AuditLog::ATTENDANCE_VERIFICATION_FAILED, now());
        $this->seedAudit(AuditLog::PAYROLL_APPROVED, now()->subDays(40));

        // Nav `reports`: HR full, engineer view, exec full. Foreman: none.
        $this->actingAs($this->foreman, 'sanctum')->getJson('/api/reports/audit')->assertForbidden();
        $this->actingAs($this->foreman, 'sanctum')->getJson('/api/reports/overview')->assertForbidden();
        $this->actingAs($this->loginUser('hr'), 'sanctum')->getJson('/api/reports/audit')->assertOk();
        $this->actingAs($this->loginUser('engineer'), 'sanctum')->getJson('/api/reports/audit')->assertOk();

        $executive = $this->loginUser('executive');

        $this->actingAs($executive, 'sanctum')
            ->getJson('/api/reports/audit')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.pages', 1);

        $this->actingAs($executive, 'sanctum')
            ->getJson('/api/reports/audit?action='.AuditLog::LATE_OVERRIDE)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', AuditLog::LATE_OVERRIDE);

        $this->actingAs($executive, 'sanctum')
            ->getJson('/api/reports/audit?from=2026-08-19&to=2026-09-17')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($executive, 'sanctum')
            ->getJson('/api/reports/audit?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.pages', 2);
    }

    public function test_flagged_feed_is_windowed_and_type_limited(): void
    {
        // Not flagged: a payroll approval inside the window must be absent.
        $this->seedAudit(AuditLog::PAYROLL_APPROVED, now()->subDays(3));
        $this->seedAudit(AuditLog::RECOVERY_SIGNED_OFF, now()->subDays(2));
        $this->seedAudit(AuditLog::ATTENDANCE_VERIFICATION_FAILED, now()->subDay());
        $this->seedAudit(AuditLog::LATE_OVERRIDE, now());
        $this->seedAudit(AuditLog::LATE_OVERRIDE, now()->subDays(40));
        // The recovery head itself is flagged, like each of its steps.
        $this->seedAudit(AuditLog::RETROACTIVE_RECOVERY, now()->subHours(2));

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonCount(4, 'data.flagged_audits')
            ->assertJsonPath('data.flagged_audits.0.action', AuditLog::RETROACTIVE_RECOVERY)
            ->assertJsonPath('data.flagged_audits.1.action', AuditLog::LATE_OVERRIDE);
    }

    public function test_absence_on_rest_days_and_holidays_is_not_an_absence(): void
    {
        $sunday = Carbon::parse('2026-09-01', config('attendance.timezone'))->next(Carbon::SUNDAY);

        Holiday::create(['date' => '2026-09-14', 'name' => 'Test Holiday', 'type' => 'regular']);

        $restDay = $this->worker();
        $holiday = $this->worker();
        $worked = $this->worker();
        $absent = $this->worker();

        Attendance::create([
            'employee_id' => $restDay->employee_id,
            'crew_id' => $this->crewId,
            'date' => $sunday->toDateString(),
            'status' => 'absent',
            'sync_status' => 'synced',
        ]);
        Attendance::create([
            'employee_id' => $holiday->employee_id,
            'crew_id' => $this->crewId,
            'date' => '2026-09-14',
            'status' => 'absent',
            'sync_status' => 'synced',
        ]);
        $this->attendance($worked->employee_id, 'present');
        $this->attendance($absent->employee_id, 'absent');

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.attendance.present', 1)
            ->assertJsonPath('data.attendance.absent', 1)
            ->assertJsonPath('data.kpis.attendance_rate', 50)
            ->assertJsonPath('data.kpis.absence_rate', 50)
            ->assertJsonPath('data.sites.0.attendance.absent', 1);
    }

    public function test_engineer_views_only_their_own_site(): void
    {
        $own = $this->site();
        $other = $this->site('Site 02 — Other');

        $crewOther = Crew::factory()->create([
            'site_id' => $other->site_id,
            'foreman_id' => $this->foreman->employee_id,
            'status' => 'deployed',
        ]);

        $workerOther = Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $other->site_id,
        ]);
        Attendance::create([
            'employee_id' => $workerOther->employee_id,
            'crew_id' => $crewOther->crew_id,
            'date' => '2026-09-12',
            'status' => 'present',
            'sync_status' => 'synced',
        ]);

        // One override at each site: own (the seeded crew) and other.
        $ownOverride = $this->seedAudit(AuditLog::LATE_OVERRIDE, now());
        AuditLog::create([
            'actor_id' => $this->foreman->employee_id,
            'crew_id' => $crewOther->crew_id,
            'subject_date' => '2026-09-12',
            'action_type' => AuditLog::LATE_OVERRIDE,
            'description' => 'Override at the other site',
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_PENDING,
        ]);

        $engineer = $this->loginUser('engineer');

        // Even when asked for the other site, the engineer is clamped to
        // their own: site, headcount, and rows all report the own site only.
        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/reports/overview?site_id='.$other->site_id)
            ->assertOk()
            ->assertJsonPath('data.site.site_id', $own->site_id)
            ->assertJsonPath('data.kpis.headcount', 2)
            ->assertJsonPath('data.kpis.sites_active', 1)
            ->assertJsonPath('data.sites.0.site_id', $own->site_id)
            ->assertJsonPath('data.sites.0.attendance.present', 0);

        // The audit log follows the same clamp: own crew events visible, the
        // other site's override never.
        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/reports/audit?site_id='.$other->site_id)
            ->assertOk()
            ->assertJsonPath('data.0.audit_id', $ownOverride->audit_id)
            ->assertJsonCount(1, 'data');
    }

    public function test_office_only_sites_are_not_reporting_sites(): void
    {
        $office = $this->site('Head Office');
        Employee::factory()->create([
            'role_id' => $this->role('executive')->role_id,
            'site_id' => $office->site_id,
            'password' => 'password',
        ]);

        $response = $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.sites_active', 1);

        $this->assertSame(
            0,
            collect($response->json('data.sites'))->where('site_id', $office->site_id)->count(),
        );
    }

    public function test_sites_with_no_staff_or_activity_are_omitted(): void
    {
        $this->site('Site 02 — Vacant');

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonCount(1, 'data.sites')
            ->assertJsonPath('data.kpis.sites_active', 1);
    }

    /**
     * Leave is charged by home site, so an office clerk's approved leave used
     * to make Head Office a reporting site — with its own score, weighting
     * the company average. Only a crew or field staff make a work site.
     */
    public function test_office_staff_leave_does_not_make_the_office_a_work_site(): void
    {
        $office = $this->site('Head Office');
        $clerk = Employee::factory()->create([
            'role_id' => $this->role('hr')->role_id,
            'site_id' => $office->site_id,
        ]);
        LeaveRequest::query()->create([
            'employee_id' => $clerk->employee_id,
            'filed_by' => $clerk->employee_id,
            'leave_type' => 'vacation',
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-11',
            'status' => LeaveRequest::APPROVED,
        ]);

        $response = $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.sites_active', 1)
            // The leave itself is still reported company-wide.
            ->assertJsonPath('data.leaves.requests', 1);

        $this->assertSame(
            0,
            collect($response->json('data.sites'))->where('site_id', $office->site_id)->count(),
        );
    }

    /** The engineer clamp fails closed: no home site means no report, not the whole company. */
    public function test_an_engineer_without_a_home_site_is_refused(): void
    {
        $engineer = $this->loginUser('engineer');
        $engineer->update(['site_id' => null]);

        $this->actingAs($engineer->fresh(), 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertForbidden();

        $this->actingAs($engineer->fresh(), 'sanctum')
            ->getJson('/api/reports/audit')
            ->assertForbidden();
    }

    private function attendance(int $employeeId, string $status, ?int $crewId = null): Attendance
    {
        return Attendance::create([
            'employee_id' => $employeeId,
            'crew_id' => $crewId ?? $this->crewId,
            'date' => '2026-09-12',
            'status' => $status,
            'sync_status' => 'synced',
        ]);
    }

    private function seedAudit(string $action, CarbonInterface $timestamp): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $this->foreman->employee_id,
            'crew_id' => $this->crewId,
            'subject_date' => $timestamp->copy()->setTimezone(config('attendance.timezone'))->toDateString(),
            'action_type' => $action,
            'description' => 'Test audit row for '.$action,
            'timestamp' => $timestamp,
            'review_status' => in_array($action, AuditLog::OVERRIDE_TYPES, true) ? AuditLog::REVIEW_PENDING : null,
        ]);
    }
}
