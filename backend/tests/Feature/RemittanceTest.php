<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Services\Payroll\PayPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gov't Remittances (C3, UC-08/FR-08) — a monthly total built from the
 * month's approved A and B runs. HR sees per-employee rows with the ID
 * numbers; the executive sees totals and counts only.
 */
class RemittanceTest extends TestCase
{
    use RefreshDatabase;

    private function payrollRun(Employee $employee, string $code, string $status, array $shares = []): Payroll
    {
        $period = PayPeriod::fromCode($code);

        $payroll = Payroll::create([
            'employee_id' => $employee->employee_id,
            'run_code' => $code,
            'pay_period_start' => $period->start,
            'pay_period_end' => $period->end,
            'gross_pay' => 8000.00,
            'net_pay' => 7200.00,
            'status' => $status,
            'approved_at' => $status === Payroll::APPROVED ? now() : null,
        ]);

        PayrollDetail::create(array_merge([
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
        ], $shares));

        return $payroll;
    }

    private function staffer(string $role, array $ids = []): Employee
    {
        return $this->loginUser($role, array_merge([
            'sss' => '33-8812445-1',
            'philhealth' => '12-104778812-4',
            'pag_ibig' => '1210-4477-8891',
            'tin' => '284-119-045',
        ], $ids));
    }

    public function test_totals_equal_the_sum_of_approved_payslip_lines(): void
    {
        $hr = $this->loginUser('hr');
        $a = $this->staffer('worker');
        $b = $this->staffer('worker');
        $this->payrollRun($a, '2026-09-A', Payroll::APPROVED);
        $this->payrollRun($a, '2026-09-B', Payroll::APPROVED);
        $this->payrollRun($b, '2026-09-A', Payroll::APPROVED);
        $this->payrollRun($b, '2026-09-B', Payroll::APPROVED);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/payroll/remittances?month=2026-09')
            ->assertOk();

        // 4 runs × 400 SSS employee = 1600; employer 4 × 800 = 3200.
        $this->assertEquals(1600, $response->json('totals.sss.employee'));
        $this->assertEquals(3200, $response->json('totals.sss.employer'));
        $this->assertEquals(4800, $response->json('totals.sss.total'));
        $this->assertEquals(400, $response->json('totals.withholding_tax'));
        $this->assertFalse($response->json('partial'));
        $this->assertSame('approved', $response->json('periods.0.state'));
        $this->assertSame('approved', $response->json('periods.1.state'));
        $this->assertSame(2, $response->json('counts.employees'));
        $this->assertSame(4, $response->json('counts.runs'));

        // HR rows carry the ID numbers the remittance is filed under.
        $rows = $response->json('rows');
        $this->assertSame(2, count($rows));
        $this->assertSame('33-8812445-1', $rows[0]['ids']['sss']);
        $this->assertSame('284-119-045', $rows[0]['ids']['tin']);
        $this->assertSame([], $rows[0]['warnings']);
    }

    public function test_drafts_are_left_out_and_the_month_reads_partial(): void
    {
        $hr = $this->loginUser('hr');
        $a = $this->staffer('worker');
        $this->payrollRun($a, '2026-09-A', Payroll::APPROVED);
        $this->payrollRun($a, '2026-09-B', Payroll::DRAFT);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/payroll/remittances?month=2026-09')
            ->assertOk();

        // Only the approved A run counts: 1 × 400, not 2 × 400.
        $this->assertEquals(400, $response->json('totals.sss.employee'));
        $this->assertTrue($response->json('partial'));
        $this->assertSame('approved', $response->json('periods.0.state'));
        $this->assertSame('draft', $response->json('periods.1.state'));
    }

    public function test_uncomputed_half_reads_not_computed(): void
    {
        $hr = $this->loginUser('hr');
        $a = $this->staffer('worker');
        $this->payrollRun($a, '2026-09-A', Payroll::APPROVED);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/payroll/remittances?month=2026-09')
            ->assertOk();

        $this->assertSame('not_computed', $response->json('periods.1.state'));
        $this->assertTrue($response->json('partial'));
    }

    public function test_missing_id_numbers_are_warned(): void
    {
        $hr = $this->loginUser('hr');
        $a = $this->staffer('worker', ['sss' => null, 'tin' => null]);
        $this->payrollRun($a, '2026-09-A', Payroll::APPROVED);
        $this->payrollRun($a, '2026-09-B', Payroll::APPROVED);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/payroll/remittances?month=2026-09')
            ->assertOk();

        $rows = $response->json('rows');
        $this->assertSame(1, count($rows));
        $this->assertContains('No sss number on file.', $rows[0]['warnings']);
        $this->assertContains('No TIN on file.', $rows[0]['warnings']);
    }

    public function test_executive_gets_totals_only_with_no_id_numbers(): void
    {
        $executive = $this->loginUser('executive');
        $a = $this->staffer('worker');
        $this->payrollRun($a, '2026-09-A', Payroll::APPROVED);

        $response = $this->actingAs($executive, 'sanctum')
            ->getJson('/api/payroll/remittances?month=2026-09')
            ->assertOk();

        $this->assertEquals(400, $response->json('totals.sss.employee'));
        $this->assertArrayNotHasKey('rows', $response->json());

        $body = $response->getContent();
        foreach (['33-8812445-1', '12-104778812-4', '1210-4477-8891', '284-119-045'] as $id) {
            $this->assertStringNotContainsString($id, $body);
        }
    }

    public function test_invalid_months_are_rejected_and_other_roles_denied(): void
    {
        $hr = $this->loginUser('hr');

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/payroll/remittances?month=2026-13')
            ->assertUnprocessable();
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/payroll/remittances?month=september')
            ->assertUnprocessable();
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/payroll/remittances')
            ->assertUnprocessable();

        foreach (['engineer', 'foreman', 'admin'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/payroll/remittances?month=2026-09')
                ->assertForbidden();
        }
    }
}
