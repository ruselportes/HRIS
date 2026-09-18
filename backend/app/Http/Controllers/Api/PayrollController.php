<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Payroll\PayPeriod;
use App\Services\Payroll\PayrollRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Payroll Run (Phase 8 — UC-08, STD TC-06), backing
 * docs/prototypes/HRIS Payroll Run.dc.html. HR computes and approves;
 * executives may read, per the nav access matrix.
 */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollRuns $runs) {}

    /** GET /api/payroll/runs — the recent cut-offs and where each stands. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->runs->recent()]);
    }

    /** GET /api/payroll/runs/{code} */
    public function show(string $code): JsonResponse
    {
        return response()->json(['data' => $this->runs->show($this->period($code))]);
    }

    /** POST /api/payroll/runs/{code}/compute — compute, or recompute the draft rows. */
    public function compute(string $code): JsonResponse
    {
        $period = $this->period($code);
        $this->runs->compute($period);

        return response()->json(['data' => $this->runs->show($period)]);
    }

    /** POST /api/payroll/runs/{code}/approve — approve the rows that may be paid. */
    public function approve(Request $request, string $code): JsonResponse
    {
        $period = $this->period($code);
        $approved = $this->runs->approve($period, $request->user());

        return response()->json(['approved' => $approved, 'data' => $this->runs->show($period)]);
    }

    /** GET /api/payroll/runs/{code}/employees/{employee} — one payslip. */
    public function payslip(string $code, Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->runs->payslip($this->period($code), $employee)]);
    }

    private function period(string $code): PayPeriod
    {
        try {
            return PayPeriod::fromCode($code);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }
    }
}
