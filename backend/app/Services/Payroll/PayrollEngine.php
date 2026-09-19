<?php

namespace App\Services\Payroll;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Services\Attendance\RecoveryService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Philippine Labor Code payroll engine (Phase 8 — UC-08, STD TC-06).
 *
 * For each worker with attendance in a cut-off, day by day: classify the day
 * (ordinary, rest day, special or regular holiday, or combinations), pay the
 * regular hours at the day rate — to the time-out if the worker left early —
 * pay approved overtime on top of the day rate with its night hours on top of
 * that, and pay unworked regular holidays to those entitled. Then the
 * statutory deductions and tax.
 *
 * Only attendance payroll may use is paid (Attendance::isPayrollReady): an
 * override or stated time-out HR has not reviewed, a recovery HR has not
 * signed, or a record the clock check flagged holds that worker's row as
 * Blocked, with the reason, so nobody is paid short on data that may still
 * change. A worked day with no time-out is paid to shift end with a warning,
 * not held: before phones captured time-outs, no day had one.
 *
 * Every figure is recorded day by day in the breakdown, so a payslip can be
 * traced back to the roll call and overtime that produced it.
 */
class PayrollEngine
{
    public function __construct(
        private readonly PayrollRates $rates,
        private readonly RateCalculator $calculator,
        private readonly TimeWorked $time,
        private readonly StatutoryDeductions $statutory,
        private readonly RecoveryService $recovery,
    ) {}

    /**
     * Compute (or recompute) a cut-off's draft payroll. Rows already approved
     * are final and left alone.
     *
     * @return Collection<int, Payroll>
     */
    public function run(PayPeriod $period): Collection
    {
        // A week before the period too: an unworked regular holiday early in
        // the period is paid only if the worker was present on the working
        // day before it.
        $lookBack = Carbon::parse($period->start)->subDays(7)->toDateString();

        $attendance = Attendance::query()
            ->whereBetween('date', [$lookBack, $period->end])
            ->with('employee', 'cryptoSignature', 'overrideEvent', 'timeOutEvent')
            ->get()
            ->groupBy('employee_id');

        $overtime = OvertimeRequest::query()
            ->where('status', OvertimeRequest::APPROVED)
            ->whereBetween('ot_date', [$period->start, $period->end])
            ->get()
            ->groupBy('employee_id');

        $holidays = Holiday::query()
            ->whereBetween('date', [$lookBack, $period->end])
            ->get()
            ->groupBy(fn (Holiday $h) => substr((string) $h->date, 0, 10));

        $unresolved = $this->recovery->unresolvedDays($period->start, $period->end);

        $inPeriod = $attendance->filter(fn (Collection $rows) => $rows->contains(
            fn (Attendance $a) => $this->day($a->date) >= $period->start
        ));

        return DB::transaction(function () use ($period, $inPeriod, $overtime, $holidays, $unresolved) {
            $computed = $inPeriod->map(fn (Collection $rows, $employeeId) => $this->persist(
                $period,
                $rows->first()->employee,
                $this->compute($period, $rows->first()->employee, $rows, $overtime->get($employeeId, collect()), $holidays, $unresolved),
            ));

            // Anyone no longer in the run (their attendance moved or went) has
            // their stale draft removed.
            Payroll::query()
                ->where('pay_period_start', $period->start)
                ->where('status', Payroll::DRAFT)
                ->whereNotIn('employee_id', $inPeriod->keys())
                ->each(function (Payroll $p) {
                    $p->detail()->delete();
                    $p->delete();
                });

            return $computed->filter()->values();
        });
    }

    /**
     * One worker's cut-off, computed but not saved.
     *
     * @return array<string, mixed>
     */
    public function compute(
        PayPeriod $period,
        Employee $employee,
        Collection $attendance,
        Collection $overtime,
        Collection $holidays,
        Collection $unresolved,
    ): array {
        $byDate = $attendance->keyBy(fn (Attendance $a) => $this->day($a->date));
        $overtimeByDate = $overtime->groupBy(fn (OvertimeRequest $o) => $this->day($o->ot_date));
        $crewIds = CrewAssignment::query()
            ->where('employee_id', $employee->employee_id)
            ->where('assignment_type', CrewAssignment::TYPE_MEMBER)
            ->pluck('crew_id')
            ->merge($attendance->pluck('crew_id'))
            ->unique();

        $hourly = (float) $employee->daily_rate / (float) config('payroll.rates.regular_hours_per_day', 8);
        $shift = $this->shift();
        $night = config('payroll.night');

        $lines = [];
        $blocked = [];
        $warnings = [];
        $recovered = false;
        $buckets = [
            'regular_hours' => 0.0,
            'overtime_hours' => 0.0,
            'night_diff_hours' => 0.0,
            'rest_day_hours' => 0.0,
            'holiday_hours' => 0.0,
            'unworked_holiday_hours' => 0.0,
        ];
        $basic = 0.0;

        if ((float) $employee->daily_rate <= 0) {
            $blocked[] = ['date' => null, 'reason' => 'No daily rate on file'];
        }

        foreach ($period->dates() as $date) {
            $premiums = $this->rates->premiumsOn($date);
            [$dayType, $regularHolidays] = $this->dayType($date, $holidays);
            $record = $byDate->get($date);

            if ($record !== null && ! $record->isPayrollReady()) {
                $blocked[] = ['date' => $date, 'reason' => $this->notReadyReason($record)];

                continue;
            }

            if ($record === null && $crewIds->contains(fn ($crewId) => $unresolved->has("{$crewId}|{$date}"))) {
                $blocked[] = ['date' => $date, 'reason' => 'Crew-day with no roll call, awaiting recovery'];
            }

            $worked = $record !== null && in_array($record->status, ['present', 'late'], true);

            if ($worked) {
                $recovered = $recovered || $record->isReconstructed();
                $leftAt = $this->siteMinutes($record->effectiveTimeOut());

                if ($leftAt === null && $this->expectsTimeOut($record, $date)) {
                    $warnings[] = "No time-out recorded on {$date}: paid to the end of the shift.";
                }

                $hours = $this->time->regularHours($record->status, $this->arrival($record), $shift, $leftAt);
                $multiplier = $this->calculator->multiplier($dayType, false, false, $premiums);
                $line = $this->line($date, 'regular', $dayType, $hours, $multiplier, $hourly);

                if ($leftAt !== null && $leftAt < $this->time->minutes($shift['end'])) {
                    // Undertime: say where the day was cut short.
                    $line['time_out'] = $this->clock($leftAt);
                }

                $lines[] = $line;
                $basic += $this->calculator->pay($hourly, $hours, 1.0);
                $buckets[$this->bucketFor($dayType)] += $hours;

                // Close shift credits shift end to everyone still on site; it
                // says nobody left early, not when anyone left after. Only a
                // time-out that records the actual leaving can cut overtime.
                $overtimeCap = $record->time_out_type === Attendance::TIME_OUT_SHIFT_END ? null : $leftAt;

                foreach ($overtimeByDate->get($date, []) as $request) {
                    // Overtime is paid from its window. Filing now requires
                    // one; a row approved before that rule has nothing to pay
                    // from, so it is reported rather than failing the run.
                    if ($request->start_time === null || $request->end_time === null) {
                        $warnings[] = "Approved overtime on {$date} has no time window; not paid.";

                        continue;
                    }

                    $window = $this->time->overtime($request->start_time, $request->end_time, $shift, $night, $overtimeCap);

                    if ($overtimeCap !== null) {
                        $approved = $this->time->overtime($request->start_time, $request->end_time, $shift, $night);

                        if ($window['hours'] < $approved['hours'] - 1e-9) {
                            $at = $this->clock($overtimeCap);
                            $warnings[] = $window['hours'] > 1e-9
                                ? "Approved overtime on {$date} paid only to {$at}, when the worker was timed out."
                                : "Approved overtime on {$date} not paid: the worker was timed out at {$at}, before it began.";
                        }
                    }

                    $dayHours = $window['hours'] - $window['night_hours'];

                    if ($dayHours > 1e-9) {
                        $lines[] = $this->line($date, 'overtime', $dayType, $dayHours, $this->calculator->multiplier($dayType, true, false, $premiums), $hourly);
                    }

                    if ($window['night_hours'] > 0) {
                        $lines[] = $this->line($date, 'overtime_night', $dayType, $window['night_hours'], $this->calculator->multiplier($dayType, true, true, $premiums), $hourly);
                    }

                    $buckets['overtime_hours'] += $window['hours'];
                    $buckets['night_diff_hours'] += $window['night_hours'];
                }

                continue;
            }

            if ($overtimeByDate->has($date)) {
                // Overtime is work beyond a day's regular hours; with no roll
                // call that day there is nothing it can be beyond.
                $warnings[] = "Approved overtime on {$date} not paid: no roll call for that day.";
            }

            if ($regularHolidays > 0 && $this->presentBefore($date, $byDate, $holidays)) {
                $hours = (float) config('payroll.rates.regular_hours_per_day', 8);
                $multiplier = $regularHolidays * (float) $premiums['unworked_regular_holiday'];
                $lines[] = $this->line($date, 'unworked_holiday', $dayType, $hours, $multiplier, $hourly);
                $basic += $this->calculator->pay($hourly, $hours, $multiplier);
                $buckets['unworked_holiday_hours'] += $hours;
            }
        }

        $gross = round(array_sum(array_column($lines, 'amount')), 2);
        $basic = round($basic, 2);

        foreach ($this->approvedLeaves($period, $employee) as $leave) {
            $warnings[] = $this->silWarning($leave, $period, $byDate);
        }
        $statutory = $this->statutory->compute($gross, $basic, (float) $employee->daily_rate, $period->end);

        $deductions = round(
            $statutory['sss_employee'] + $statutory['philhealth_employee'] + $statutory['pagibig_employee'] + $statutory['withholding_tax'],
            2,
        );

        return [
            'gross_pay' => $gross,
            'net_pay' => round($gross - $deductions, 2),
            'basic_pay' => $basic,
            'premium_pay' => round($gross - $basic, 2),
            'deductions' => $deductions,
            'other_deductions' => 0.0,
            'readiness' => $blocked !== [] ? PayrollDetail::BLOCKED : ($recovered ? PayrollDetail::RECOVERED : PayrollDetail::READY),
            'blocked_reasons' => $blocked === [] ? null : $blocked,
            'breakdown' => ['lines' => $lines, 'warnings' => $warnings, 'hourly_rate' => round($hourly, 4)],
        ] + array_map(fn ($h) => round($h, 2), $buckets) + $statutory;
    }

    private function persist(PayPeriod $period, Employee $employee, array $result): ?Payroll
    {
        $payroll = Payroll::query()->firstOrNew([
            'employee_id' => $employee->employee_id,
            'pay_period_start' => $period->start,
        ]);

        if ($payroll->exists && $payroll->status !== Payroll::DRAFT) {
            return null;
        }

        $payroll->fill([
            'run_code' => $period->code,
            'pay_period_end' => $period->end,
            'gross_pay' => $result['gross_pay'],
            'net_pay' => $result['net_pay'],
            'status' => Payroll::DRAFT,
        ])->save();

        $payroll->detail()->updateOrCreate(['payroll_id' => $payroll->payroll_id], collect($result)
            ->except(['gross_pay', 'net_pay'])
            ->all());

        return $payroll->load('detail', 'employee');
    }

    /**
     * The worker's approved leaves that cross this cut-off, as SIL material.
     * An absent day covered by approved leave may be a paid Service Incentive
     * Leave day (Art. 95, PD 442) — the payslip flags it so nobody pays it
     * twice or forgets it entirely. This warns only; entitlement depends on
     * the worker's remaining allowance.
     */
    private function approvedLeaves(PayPeriod $period, Employee $employee): Collection
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->employee_id)
            ->where('status', LeaveRequest::APPROVED)
            ->where('date_from', '<=', $period->end)
            ->where('date_to', '>=', $period->start)
            ->get();
    }

    /** @param  Collection<string, Attendance>  $byDate */
    private function silWarning(LeaveRequest $leave, PayPeriod $period, Collection $byDate): ?string
    {
        $covered = collect();

        foreach (CarbonPeriod::create($leave->date_from, $leave->date_to) as $day) {
            $date = $day->toDateString();

            if ($date < $period->start || $date > $period->end) {
                continue;
            }

            $record = $byDate->get($date);

            if ($record !== null && in_array($record->status, ['present', 'late'], true)) {
                continue;
            }

            $covered->push($date);
        }

        if ($covered->isEmpty()) {
            return null;
        }

        return "Approved {$leave->leave_type} leave on {$covered->implode(', ')}: "
            .'SIL credit only if within the worker\'s annual allowance — verify before paying.';
    }

    /** @return array{0:string, 1:int} day type and how many regular holidays fall on it */
    private function dayType(string $date, Collection $holidays): array
    {
        $onDate = $holidays->get($date, collect());
        $regular = $onDate->where('type', Holiday::REGULAR)->count();
        $special = $onDate->where('type', Holiday::SPECIAL)->count();
        $restDay = Carbon::parse($date)->dayOfWeekIso === (int) config('payroll.rest_day_iso', 7);

        return [$this->calculator->dayType($restDay, $regular, $special), $regular];
    }

    /**
     * Art. 94 eligibility for an unworked regular holiday: present on the
     * working day before it. Rest days and other holidays in between are
     * skipped, since there was no work to be present for.
     */
    private function presentBefore(string $date, Collection $byDate, Collection $holidays): bool
    {
        $day = Carbon::parse($date);

        for ($i = 0; $i < 7; $i++) {
            $day->subDay();
            $previous = $day->toDateString();

            if ($day->dayOfWeekIso === (int) config('payroll.rest_day_iso', 7) || $holidays->has($previous)) {
                continue;
            }

            $record = $byDate->get($previous);

            return $record !== null && in_array($record->status, ['present', 'late'], true) && $record->isPayrollReady();
        }

        return false;
    }

    private function bucketFor(string $dayType): string
    {
        return match ($dayType) {
            RateCalculator::ORDINARY => 'regular_hours',
            RateCalculator::REST_DAY => 'rest_day_hours',
            default => 'holiday_hours',
        };
    }

    private function line(string $date, string $kind, string $dayType, float $hours, float $multiplier, float $hourly): array
    {
        return [
            'date' => $date,
            'kind' => $kind,
            'day_type' => $dayType,
            'hours' => round($hours, 4),
            'multiplier' => $multiplier,
            'amount' => $this->calculator->pay($hourly, $hours, $multiplier),
        ];
    }

    /** Minutes from midnight, site time, of a Late worker's credited arrival. */
    private function arrival(Attendance $record): ?int
    {
        return $record->status === 'late' ? $this->siteMinutes($record->effectiveTimeIn()) : null;
    }

    /** Minutes from midnight, site time, of an instant on the day. */
    private function siteMinutes(?Carbon $at): ?int
    {
        if ($at === null) {
            return null;
        }

        $site = $at->copy()->setTimezone(config('attendance.timezone', 'Asia/Manila'));

        return $site->hour * 60 + $site->minute;
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Whether a worked day should have had a time-out. Not before phones could
     * capture one, and not for a day rebuilt through recovery, which records
     * arrival only.
     */
    private function expectsTimeOut(Attendance $record, string $date): bool
    {
        return ! $record->isReconstructed()
            && $date >= (string) config('attendance.time_out_tracked_from', '2026-09-21');
    }

    private function notReadyReason(Attendance $record): string
    {
        if ($record->isReconstructed()) {
            return 'Reconstructed attendance awaiting HR sign-off';
        }

        if ($record->cryptoSignature?->verified !== true) {
            return 'Attendance flagged by the clock check, awaiting HR review';
        }

        $timeInUndecided = $record->override_flag !== null && ! in_array(
            $record->overrideEvent?->review_status,
            [AuditLog::REVIEW_APPROVED, AuditLog::REVIEW_REJECTED],
            true,
        );

        if (! $timeInUndecided) {
            return 'Foreman-set time-out awaiting HR review';
        }

        return $record->overrideEvent?->action_type === AuditLog::LATE_OVERRIDE
            ? 'Late-start shift credit awaiting HR review'
            : 'Foreman-set arrival time awaiting HR review';
    }

    private function shift(): array
    {
        return [
            'start' => config('attendance.shift_start', '07:00'),
            'end' => config('attendance.shift_end', '16:00'),
            'meal_start' => config('payroll.shift.meal_start', '12:00'),
            'meal_end' => config('payroll.shift.meal_end', '13:00'),
        ];
    }

    private function day($date): string
    {
        return substr((string) $date, 0, 10);
    }
}
