<?php

namespace Tests\Concerns;

use App\Models\Attendance;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\CryptoSignature;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Services\Payroll\PayPeriod;
use Illuminate\Support\Carbon;

/**
 * Payroll fixtures (Phase 8): a deployed crew, the holidays of period
 * 2026-09-A, and signed roll call — what the engine may pay on.
 *
 * 2026-09-A runs Fri 21 Aug - Sat 05 Sep 2026. Fri 21 Aug is Ninoy Aquino
 * Day (special), Mon 31 Aug National Heroes Day (regular). Sundays are the
 * rest day.
 */
trait BuildsPayrollFixtures
{
    protected Crew $crew;

    /** A crew-mate on roll call (absent) every working day, so no day is a recovery gap by accident. */
    protected Employee $anchor;

    protected function setUpPayrollCrew(): void
    {
        $this->crew = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $this->loginUser('foreman')->employee_id,
            'status' => 'deployed',
            'deployed_at' => Carbon::parse('2026-08-01 06:00', 'Asia/Manila'),
        ]);

        Holiday::query()->create(['date' => '2026-08-21', 'name' => 'Ninoy Aquino Day', 'type' => Holiday::SPECIAL]);
        Holiday::query()->create(['date' => '2026-08-31', 'name' => 'National Heroes Day', 'type' => Holiday::REGULAR]);

        $this->anchor = $this->payrollWorker(600);
        foreach (PayPeriod::fromCode('2026-09-A')->dates() as $date) {
            if (! Carbon::parse($date)->isSunday() && ! in_array($date, ['2026-08-21', '2026-08-31'], true)) {
                $this->absentDays($this->anchor, [$date]);
            }
        }
    }

    protected function payrollWorker(float $dailyRate): Employee
    {
        $worker = Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site()->site_id,
            'daily_rate' => $dailyRate,
        ]);

        CrewAssignment::factory()->create([
            'crew_id' => $this->crew->crew_id,
            'employee_id' => $worker->employee_id,
            'status' => 'active',
        ]);

        return $worker;
    }

    protected function workedDays(Employee $worker, array $dates, string $status = 'present', string $time = '06:55'): void
    {
        foreach ($dates as $date) {
            $this->signed($worker, $date, $status, $time);
        }
    }

    protected function absentDays(Employee $worker, array $dates): void
    {
        foreach ($dates as $date) {
            $this->signed($worker, $date, 'absent', null);
        }
    }

    /** A synced, signature-verified roll call record — what payroll may use. */
    protected function signed(Employee $worker, string $date, string $status, ?string $time, array $extra = []): Attendance
    {
        $attendance = Attendance::query()->create([
            'employee_id' => $worker->employee_id,
            'crew_id' => $this->crew->crew_id,
            'date' => $date,
            'status' => $status,
            // Stored in UTC, as sync and recovery store them.
            'time_in' => $time === null ? null : Carbon::parse("{$date} {$time}", 'Asia/Manila')->utc(),
            'captured_at' => Carbon::parse("{$date} ".($time ?? '07:00'), 'Asia/Manila')->utc(),
            'sync_status' => 'synced',
        ] + $extra);

        CryptoSignature::query()->create([
            'attendance_id' => $attendance->attendance_id,
            'hmac_hash' => hash('sha256', "{$worker->employee_id}{$date}"),
            'ecdsa_signature' => 'test',
            'verified' => true,
        ]);

        return $attendance;
    }

    /** Remove roll call (and its signature) for a day — one worker's, or everyone's. */
    protected function forget(?Employee $worker, string $date): void
    {
        $rows = Attendance::query()
            ->where('date', $date)
            ->when($worker, fn ($q) => $q->where('employee_id', $worker->employee_id))
            ->pluck('attendance_id');

        CryptoSignature::query()->whereIn('attendance_id', $rows)->delete();
        Attendance::query()->whereIn('attendance_id', $rows)->delete();
    }

    protected function overtime(Employee $worker, string $date, string $start, string $end): void
    {
        OvertimeRequest::query()->create([
            'employee_id' => $worker->employee_id,
            'ot_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'status' => OvertimeRequest::APPROVED,
        ]);
    }
}
