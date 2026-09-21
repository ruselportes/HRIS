<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAttendanceRequest;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Payroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Add-on B (FR-11, UC-11) — the worker portal's own-data reads.
 *
 * Every handler derives the scope from the signed-in employee. There is no
 * employee id parameter anywhere: a worker can only ever ask about themselves,
 * and the allowlist in EnsurePortalScope already keeps the rest of the API
 * out of reach for portal roles. Nothing secret leaves the server — no hashes,
 * signatures, device ids or reviewer notes.
 */
class PortalController extends Controller
{
    /**
     * GET /api/me/attendance?from=&to= — one worker's attendance rows.
     *
     * Deliberately narrower than the staff DTR (AttendanceController): the
     * worker sees the day, its status, the credited and recorded times, how a
     * time-out was entered in plain words, and whether HR is still reviewing
     * anything on the record. No filtering beyond the window, no pagination
     * params, no ids they were not part of.
     */
    public function attendance(PortalAttendanceRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $rows = Attendance::query()
            ->with(['overrideEvent', 'timeOutEvent'])
            ->where('employee_id', $request->user()->employee_id)
            ->whereBetween('date', [$filters['from'], $filters['to']])
            ->orderBy('date')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Attendance $a) => $this->attendanceRow($a))->values(),
        ]);
    }

    /** GET /api/me/payslips — the worker's approved runs, newest first. */
    public function payslips(Request $request): JsonResponse
    {
        $rows = Payroll::query()
            ->where('employee_id', $request->user()->employee_id)
            ->where('status', Payroll::APPROVED)
            ->orderByDesc('pay_period_end')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Payroll $p) => $this->runRow($p))->values(),
        ]);
    }

    /**
     * GET /api/me/payslips/{run} — one approved payslip.
     *
     * {run} is the payroll row id, and the query pins employee + status first,
     * so a draft run, a guessed id, or someone else's run all read as the same
     * 404 — a worker cannot probe a run by id.
     */
    public function payslip(Request $request, int $run): JsonResponse
    {
        $payroll = Payroll::query()
            ->with('detail')
            ->where('payroll_id', $run)
            ->where('employee_id', $request->user()->employee_id)
            ->where('status', Payroll::APPROVED)
            ->first();

        abort_unless($payroll, 404, 'That payslip is not available.');

        return response()->json([
            'data' => $this->runRow($payroll, itemised: true),
        ]);
    }

    /** One DTR row, worker-facing. Times are the site clock, not UTC. */
    private function attendanceRow(Attendance $a): array
    {
        $tz = config('attendance.timezone', 'Asia/Manila');

        return [
            'attendance_id' => $a->attendance_id,
            'date' => $a->date,
            'status' => $a->status,
            'time_in' => $a->time_in?->copy()->setTimezone($tz)->format('H:i'),
            'time_out' => $a->time_out?->copy()->setTimezone($tz)->format('H:i'),
            // "How the time-out was recorded", in plain words.
            'time_out_source' => match ($a->time_out_type) {
                Attendance::TIME_OUT_SHIFT_END => 'Close shift credited a time out',
                Attendance::TIME_OUT_MANUAL => 'Stated by your foreman',
                default => 'Tapped on the device',
            },
            'review' => $this->reviewState($a),
        ];
    }

    /** The review state HR is responsible for, in plain words. */
    private function reviewState(Attendance $a): string
    {
        if ($a->overrideEvent?->review_status === AuditLog::REVIEW_PENDING
            || ($a->time_out_type === Attendance::TIME_OUT_MANUAL
                && $a->timeOutEvent?->review_status === AuditLog::REVIEW_PENDING)) {
            return 'Under HR review';
        }

        return 'Cleared';
    }

    /** One payslip row; itemised only on the detail read. */
    private function runRow(Payroll $p, bool $itemised = false): array
    {
        $detail = $p->detail;

        $row = [
            'run_id' => $p->payroll_id,
            'run_code' => $p->run_code,
            'period' => [
                'start' => $p->pay_period_start,
                'end' => $p->pay_period_end,
            ],
            'gross_pay' => (float) $p->gross_pay,
            'deductions' => (float) (($detail?->deductions) ?? 0),
            'net_pay' => (float) $p->net_pay,
            'approved_at' => $p->approved_at?->toIso8601String(),
        ];

        if ($itemised && $detail !== null) {
            $row['detail'] = [
                'hourly_rate' => $detail->breakdown['hourly_rate'] ?? null,
                'lines' => $detail->breakdown['lines'] ?? [],
                'warnings' => $detail->breakdown['warnings'] ?? [],
                'hours' => [
                    'regular' => (float) $detail->regular_hours,
                    'overtime' => (float) $detail->overtime_hours,
                    'night_differential' => (float) $detail->night_diff_hours,
                    'rest_day' => (float) $detail->rest_day_hours,
                    'holiday' => (float) $detail->holiday_hours,
                ],
                'basic_pay' => (float) $detail->basic_pay,
                'premium_pay' => (float) $detail->premium_pay,
                'deduction_lines' => [
                    'sss' => (float) $detail->sss_employee,
                    'philhealth' => (float) $detail->philhealth_employee,
                    'pagibig' => (float) $detail->pagibig_employee,
                    'withholding_tax' => (float) $detail->withholding_tax,
                    'other' => (float) $detail->other_deductions,
                ],
                'employer_shares' => [
                    'sss' => (float) $detail->sss_employer,
                    'philhealth' => (float) $detail->philhealth_employer,
                    'pagibig' => (float) $detail->pagibig_employer,
                ],
                'tax_note' => $detail->tax_note,
            ];
        }

        return $row;
    }
}
