<?php

namespace App\Services\Payroll;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payroll runs (Phase 8 — UC-08): a cut-off's payroll rows taken together,
 * as the Payroll Run screen shows them.
 *
 * A run is Draft until HR approves it (TC-06). Approval takes only the rows
 * that may be paid — Ready and Recovered. Blocked rows stay Draft, so the
 * run can go out without them and they are approved later, once their
 * attendance is resolved and the period recomputed.
 */
class PayrollRuns
{
    public function __construct(private readonly PayrollEngine $engine) {}

    /** The current cut-off and the ones before it, computed or not. */
    public function recent(int $count = 6): Collection
    {
        $period = PayPeriod::containing($this->today());
        $periods = collect();

        for ($i = 0; $i < $count; $i++) {
            $periods->push($period);
            $period = $period->previous();
        }

        $totals = Payroll::query()
            ->whereIn('run_code', $periods->pluck('code'))
            ->selectRaw('run_code, count(*) as employees, sum(gross_pay) as gross, sum(net_pay) as net')
            ->selectRaw("sum(case when status = 'approved' then 1 else 0 end) as approved")
            ->groupBy('run_code')
            ->get()
            ->keyBy('run_code');

        return $periods->map(function (PayPeriod $p) use ($totals) {
            $t = $totals->get($p->code);

            return [
                'code' => $p->code,
                'start' => $p->start,
                'end' => $p->end,
                'label' => $p->label(),
                'status' => $this->status((int) ($t->employees ?? 0), (int) ($t->approved ?? 0)),
                'employees' => (int) ($t->employees ?? 0),
                'gross' => round((float) ($t->gross ?? 0), 2),
                'net' => round((float) ($t->net ?? 0), 2),
            ];
        })->values();
    }

    /** Compute or recompute a run. Approved rows are left as they are. */
    public function compute(PayPeriod $period): void
    {
        if ($period->start > $this->today()) {
            throw $this->unprocessable("{$period->code} has not started yet.");
        }

        $this->engine->run($period);
    }

    /** A run's summary and rows, for the Payroll Run screen. */
    public function show(PayPeriod $period): array
    {
        $rows = $this->rows($period);
        $details = $rows->pluck('detail')->filter();

        return [
            'code' => $period->code,
            'start' => $period->start,
            'end' => $period->end,
            'label' => $period->label(),
            'working_days' => $this->workingDays($period),
            'status' => $this->status($rows->count(), $rows->where('status', Payroll::APPROVED)->count()),
            'summary' => [
                'employees' => $rows->count(),
                'sites' => $rows->pluck('employee.site_id')->filter()->unique()->count(),
                'gross' => round($rows->sum(fn (Payroll $p) => (float) $p->gross_pay), 2),
                'deductions' => round($details->sum(fn (PayrollDetail $d) => (float) $d->deductions), 2),
                'net' => round($rows->sum(fn (Payroll $p) => (float) $p->net_pay), 2),
                'blocked' => $details->where('readiness', PayrollDetail::BLOCKED)->count(),
                'approved' => $rows->where('status', Payroll::APPROVED)->count(),
                'hours' => [
                    'overtime' => round($details->sum(fn ($d) => (float) $d->overtime_hours), 2),
                    'night_diff' => round($details->sum(fn ($d) => (float) $d->night_diff_hours), 2),
                    'rest_day' => round($details->sum(fn ($d) => (float) $d->rest_day_hours), 2),
                    'holiday' => round($details->sum(fn ($d) => (float) $d->holiday_hours), 2),
                ],
                'basic' => round($details->sum(fn ($d) => (float) $d->basic_pay), 2),
            ],
            'rows' => $rows->map(fn (Payroll $p) => $this->row($p))->values(),
        ];
    }

    /** One worker's payslip for the run: every day's pay, and every deduction. */
    public function payslip(PayPeriod $period, Employee $employee): array
    {
        $payroll = $this->rows($period)->firstWhere('employee_id', $employee->employee_id);

        if ($payroll === null) {
            throw $this->unprocessable("{$employee->full_name} has no payroll in {$period->code}.");
        }

        $d = $payroll->detail;

        return $this->row($payroll) + [
            'hourly_rate' => $d->breakdown['hourly_rate'] ?? null,
            'lines' => $d->breakdown['lines'] ?? [],
            'warnings' => $d->breakdown['warnings'] ?? [],
            'premium_pay' => (float) $d->premium_pay,
            'deduction_lines' => [
                'sss' => (float) $d->sss_employee,
                'philhealth' => (float) $d->philhealth_employee,
                'pagibig' => (float) $d->pagibig_employee,
                'withholding_tax' => (float) $d->withholding_tax,
                'other' => (float) $d->other_deductions,
            ],
            'tax_note' => $d->tax_note,
            'employer_shares' => [
                'sss' => (float) $d->sss_employer,
                'philhealth' => (float) $d->philhealth_employer,
                'pagibig' => (float) $d->pagibig_employer,
            ],
        ];
    }

    /**
     * Approve every Ready and Recovered draft row. Blocked rows stay Draft —
     * approving them would pay on attendance that may still change.
     *
     * @return int rows approved
     */
    public function approve(PayPeriod $period, Employee $hr): int
    {
        return DB::transaction(function () use ($period, $hr) {
            $rows = Payroll::query()
                ->where('run_code', $period->code)
                ->where('status', Payroll::DRAFT)
                ->whereHas('detail', fn ($q) => $q->where('readiness', '!=', PayrollDetail::BLOCKED))
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                throw $this->unprocessable('Nothing in this run is ready to approve.');
            }

            Payroll::query()->whereKey($rows->modelKeys())->update([
                'status' => Payroll::APPROVED,
                'approved_by' => $hr->employee_id,
                'approved_at' => Carbon::now(),
            ]);

            $held = Payroll::query()->where('run_code', $period->code)->where('status', Payroll::DRAFT)->count();

            AuditLog::query()->create([
                'actor_id' => $hr->employee_id,
                'action_type' => AuditLog::PAYROLL_APPROVED,
                'timestamp' => Carbon::now(),
                'description' => sprintf(
                    'Approved payroll %s (%s): %d rows, gross ₱%s, net ₱%s.%s',
                    $period->code,
                    $period->label(),
                    $rows->count(),
                    number_format($rows->sum(fn ($p) => (float) $p->gross_pay), 2),
                    number_format($rows->sum(fn ($p) => (float) $p->net_pay), 2),
                    $held > 0 ? " {$held} blocked rows held in draft." : '',
                ),
            ]);

            return $rows->count();
        });
    }

    /** Working days in the period: the crew work week, less holidays. */
    public function workingDays(PayPeriod $period): int
    {
        $holidays = Holiday::query()
            ->whereBetween('date', [$period->start, $period->end])
            ->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();
        $workDays = config('attendance.work_days', [1, 2, 3, 4, 5, 6]);

        return collect($period->dates())
            ->filter(fn ($d) => in_array(Carbon::parse($d)->dayOfWeekIso, $workDays, true) && ! in_array($d, $holidays, true))
            ->count();
    }

    private function rows(PayPeriod $period): Collection
    {
        return Payroll::query()
            ->where('run_code', $period->code)
            ->with('detail', 'employee.role', 'employee.site', 'approver')
            ->get()
            ->sortBy(fn (Payroll $p) => [
                $p->detail?->readiness === PayrollDetail::BLOCKED ? 0 : 1,
                $p->employee?->last_name,
            ])
            ->values();
    }

    private function row(Payroll $p): array
    {
        $d = $p->detail;

        return [
            'payroll_id' => $p->payroll_id,
            'status' => $p->status,
            'approved_by' => $p->approver?->full_name,
            'approved_at' => $p->approved_at?->toIso8601String(),
            'employee' => [
                'employee_id' => $p->employee?->employee_id,
                'employee_code' => $p->employee?->employee_code,
                'full_name' => $p->employee?->full_name,
                'role' => $p->employee?->role?->role_name,
                'site' => $p->employee?->site?->site_name,
                'daily_rate' => (float) $p->employee?->daily_rate,
            ],
            'readiness' => $d?->readiness,
            'blocked_reasons' => $d?->blocked_reasons ?? [],
            'basic_pay' => (float) $d?->basic_pay,
            'hours' => [
                'regular' => (float) $d?->regular_hours,
                'overtime' => (float) $d?->overtime_hours,
                'night_diff' => (float) $d?->night_diff_hours,
                'rest_day' => (float) $d?->rest_day_hours,
                'holiday' => (float) $d?->holiday_hours,
                'unworked_holiday' => (float) $d?->unworked_holiday_hours,
            ],
            'gross_pay' => (float) $p->gross_pay,
            'deductions' => (float) $d?->deductions,
            'net_pay' => (float) $p->net_pay,
        ];
    }

    /** not_computed, draft, partly_approved or approved. */
    private function status(int $rows, int $approved): string
    {
        return match (true) {
            $rows === 0 => 'not_computed',
            $approved === 0 => 'draft',
            $approved < $rows => 'partly_approved',
            default => 'approved',
        };
    }

    private function today(): string
    {
        return Carbon::now(config('attendance.timezone', 'Asia/Manila'))->toDateString();
    }

    private function unprocessable(string $message): HttpResponseException
    {
        return new HttpResponseException(new JsonResponse(['message' => $message], 422));
    }
}
