<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Services\Payroll\PayPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gov't Remittances (C3, UC-08/FR-08) — a monthly total built from the
 * month's approved A and B payroll runs. Payslips already store every
 * agency share, so this endpoint only aggregates: no rates live here, and
 * the [VERIFY] flags on the configured rate sets still need checking
 * against the official issuances.
 *
 * Two views, one rule: HR sees each employee's row with the ID numbers the
 * remittance is filed under; the executive sees totals and counts only,
 * because those numbers are personal data and executive access is view-only.
 */
class RemittanceController extends Controller
{
    /**
     * GET /api/payroll/remittances?month=YYYY-MM — HR and executive.
     *
     * Only payroll rows marked approved for exactly the month's two period
     * codes count; drafts never do. Each half-month reads approved (at
     * least one approved row), draft (rows but none approved) or
     * not_computed (no rows), and the month is partial unless both halves
     * are approved.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $codes = ["{$data['month']}-A", "{$data['month']}-B"];
        $periods = [];
        $states = [];

        foreach ($codes as $code) {
            $period = PayPeriod::fromCode($code);
            $rows = Payroll::query()
                ->with(['detail', 'employee'])
                ->where('run_code', $code)
                ->get();

            $states[$code] = $rows->contains(fn (Payroll $p) => $p->status === Payroll::APPROVED)
                ? 'approved'
                : ($rows->isNotEmpty() ? 'draft' : 'not_computed');

            $periods[] = [
                'code' => $code,
                'start' => $period->start,
                'end' => $period->end,
                'state' => $states[$code],
            ];
        }

        $approved = Payroll::query()
            ->with(['detail', 'employee'])
            ->whereIn('run_code', $codes)
            ->where('status', Payroll::APPROVED)
            ->get();

        $totals = [
            'sss' => ['employee' => 0.0, 'employer' => 0.0, 'total' => 0.0],
            'philhealth' => ['employee' => 0.0, 'employer' => 0.0, 'total' => 0.0],
            'pagibig' => ['employee' => 0.0, 'employer' => 0.0, 'total' => 0.0],
            'withholding_tax' => 0.0,
        ];

        foreach ($approved as $payroll) {
            $detail = $payroll->detail;
            $totals['sss']['employee'] += (float) ($detail?->sss_employee ?? 0);
            $totals['sss']['employer'] += (float) ($detail?->sss_employer ?? 0);
            $totals['philhealth']['employee'] += (float) ($detail?->philhealth_employee ?? 0);
            $totals['philhealth']['employer'] += (float) ($detail?->philhealth_employer ?? 0);
            $totals['pagibig']['employee'] += (float) ($detail?->pagibig_employee ?? 0);
            $totals['pagibig']['employer'] += (float) ($detail?->pagibig_employer ?? 0);
            $totals['withholding_tax'] += (float) ($detail?->withholding_tax ?? 0);
        }

        foreach (['sss', 'philhealth', 'pagibig'] as $agency) {
            $totals[$agency]['total'] = $totals[$agency]['employee'] + $totals[$agency]['employer'];
        }

        $response = [
            'month' => $data['month'],
            'partial' => count(array_keys($states, 'approved')) < 2,
            'periods' => $periods,
            'totals' => $totals,
            'counts' => [
                'employees' => $approved->pluck('employee_id')->unique()->count(),
                'runs' => $approved->count(),
            ],
        ];

        // Executive: totals and counts only — no rows, no ID numbers.
        if ($request->user()->role?->slug === 'executive') {
            return response()->json($response);
        }

        $byEmployee = $approved->groupBy('employee_id');
        $rows = [];

        foreach ($byEmployee as $runs) {
            /** @var Payroll $first */
            $first = $runs->first();
            $employee = $first->employee;

            $amounts = [
                'sss' => $runs->sum(fn (Payroll $p) => (float) ($p->detail?->sss_employee ?? 0)),
                'philhealth' => $runs->sum(fn (Payroll $p) => (float) ($p->detail?->philhealth_employee ?? 0)),
                'pagibig' => $runs->sum(fn (Payroll $p) => (float) ($p->detail?->pagibig_employee ?? 0)),
                'withholding_tax' => $runs->sum(fn (Payroll $p) => (float) ($p->detail?->withholding_tax ?? 0)),
            ];

            $ids = [
                'sss' => $employee?->sss,
                'philhealth' => $employee?->philhealth,
                'pagibig' => $employee?->pag_ibig,
                'tin' => $employee?->tin,
            ];

            // The company cannot remit for someone with no ID number on file.
            $warnings = [];
            foreach (['sss' => 'sss', 'philhealth' => 'philhealth', 'pagibig' => 'pagibig'] as $agency => $idField) {
                if ($amounts[$agency] > 0 && empty($ids[$idField])) {
                    $warnings[] = "No {$agency} number on file.";
                }
            }
            if ($amounts['withholding_tax'] > 0 && empty($ids['tin'])) {
                $warnings[] = 'No TIN on file.';
            }

            $rows[] = [
                'employee_code' => $employee?->employee_code,
                'full_name' => $employee?->full_name,
                'amounts' => $amounts,
                'ids' => $ids,
                'warnings' => $warnings,
            ];
        }

        $response['rows'] = array_values($rows);

        return response()->json($response);
    }
}
