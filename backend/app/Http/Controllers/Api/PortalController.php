<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAttendanceRequest;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Payroll;
use App\Services\Payroll\PayPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
     * GET /api/me/attendance — one worker's attendance rows.
     *
     * Deliberately narrower than the staff DTR (AttendanceController): the
     * worker sees the day, its status, the credited and recorded times, how a
     * time-out was entered in plain words, and whether HR is still reviewing
     * anything on the record. No filtering beyond the window, no pagination
     * params, no ids they were not part of.
     *
     * The window is optional. With no from/to the read defaults to the
     * current pay period, and every response carries the period block the
     * page steps through — code, label, start/end, and the previous/next
     * windows (next is null once that period starts after today, so the page
     * can never step into the future). The cutoffs live in config
     * (payroll.cutoff_start_days) and are resolved here, never in JavaScript.
     * An explicit window that is not a whole pay period still works, but the
     * period block then describes the period containing `from` — a narrower
     * span than the rows. The page only ever sends exact periods, so nobody
     * sees that skew; it is documented here rather than refused so ad-hoc
     * windows keep working.
     */
    public function attendance(PortalAttendanceRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $today = Carbon::now(config('attendance.timezone', 'Asia/Manila'))->toDateString();

        if (isset($filters['from'], $filters['to'])) {
            $from = $filters['from'];
            $to = $filters['to'];
            $period = PayPeriod::containing($from);
        } else {
            $period = PayPeriod::containing($today);
            $from = $period->start;
            $to = $period->end;
        }

        $rows = Attendance::query()
            ->with(['overrideEvent', 'timeOutEvent', 'cryptoSignature'])
            ->where('employee_id', $request->user()->employee_id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        $next = $period->next();
        $previous = $period->previous();

        return response()->json([
            'data' => $rows->map(fn (Attendance $a) => $this->attendanceRow($a))->values(),
            'period' => [
                'code' => $period->code,
                'label' => $period->label(),
                'start' => $period->start,
                'end' => $period->end,
                'previous' => ['start' => $previous->start, 'end' => $previous->end],
                'next' => $next->start > $today
                    ? null
                    : ['start' => $next->start, 'end' => $next->end],
            ],
        ]);
    }

    /** GET /api/me/payslips — the worker's approved runs, newest first. */
    public function payslips(Request $request): JsonResponse
    {
        $rows = Payroll::query()
            ->with('detail')
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

        // The times payroll actually uses. effectiveTimeIn/Out return null
        // while a record is held (pending, unverified, an unapproved
        // recovery), so those rows fall back to the recorded times — the
        // worker still sees SOMETHING, and reviewState() says why it is held.
        // A rejected override pays from the real tap, so the portal shows the
        // real tap too: showing the credited 07:00 next to a payslip computed
        // from 08:40 is a pay dispute waiting to happen.
        return [
            'attendance_id' => $a->attendance_id,
            'date' => $a->date,
            'status' => $a->status,
            'time_in' => ($a->effectiveTimeIn() ?? $a->time_in)?->copy()->setTimezone($tz)->format('H:i'),
            'time_out' => ($a->effectiveTimeOut() ?? $a->time_out)?->copy()->setTimezone($tz)->format('H:i'),
            // "How the time-out was recorded", in plain words — or nothing when
            // there is no time-out at all yet: today's open row used to claim
            // "Tapped on the device" for a tap that never happened.
            'time_out_source' => ($a->effectiveTimeOut() ?? $a->time_out) === null
                ? null
                : match ($a->time_out_type) {
                    Attendance::TIME_OUT_SHIFT_END => 'Close shift credited a time out',
                    Attendance::TIME_OUT_MANUAL => 'Stated by your foreman',
                    default => 'Tapped on the device',
                },
            'review' => $this->reviewState($a),
        ];
    }

    /**
     * The review state HR is responsible for, in plain words — driven by the
     * same model logic payroll uses, so the portal cannot drift from the
     * payslip.
     */
    private function reviewState(Attendance $a): string
    {
        $statuses = array_filter([
            $a->overrideEvent?->review_status,
            $a->time_out_type === Attendance::TIME_OUT_MANUAL ? $a->timeOutEvent?->review_status : null,
        ]);

        if (array_intersect($statuses, [AuditLog::REVIEW_PENDING, AuditLog::REVIEW_RETURNED])) {
            return 'Under HR review';
        }

        if (! $a->isPayrollReady()) {
            return 'On hold — ask HR';
        }

        if ($a->time_out_type === Attendance::TIME_OUT_MANUAL
            && $a->timeOutEvent?->review_status === AuditLog::REVIEW_REJECTED) {
            // A rejected stated time-out pays only until it was entered on
            // the device — a time-out sets where pay ends, and it has no
            // worker tap to revert to.
            return 'Not accepted — paid until the time it was entered';
        }

        if ($a->overrideEvent?->review_status === AuditLog::REVIEW_REJECTED) {
            return 'Not accepted — paid from your actual tap time';
        }

        // Plain tapped rows never needed a review; this is a pay statement,
        // not an HR verdict.
        return 'Counted for pay';
    }

    /** One payslip row; itemised only on the detail read. */
    private function runRow(Payroll $p, bool $itemised = false): array
    {
        $detail = $p->detail;

        $row = [
            'run_id' => $p->payroll_id,
            'run_code' => $p->run_code,
            // The period in the same words the attendance page uses — the raw
            // code (2026-09-B) means nothing next to "06 Sep – 20 Sep 2026".
            'label' => PayPeriod::fromCode($p->run_code)->label(),
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
