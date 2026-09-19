<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Leave\RequestWorkflowService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Phase 9 (UC-10) demo workflow data, separately runnable and idempotent:
 *
 *   php artisan db:seed --class=RequestWorkflowSeeder
 *
 * 1. Gives the second HR account a sign-in: ADC-0177 Divina C. Cortes exists
 *    as an HR personnel record with no password (record-only); this is the
 *    account the two-hop approvals can be demonstrated between — Marilou
 *    fills one side, Divina the other. Dev-only password "password".
 * 2. Seeds the validation cases the team demos: a pending leave awaiting its
 *    endorser, an endorsed leave awaiting HR, and a batch of overtime nights.
 *
 * Demo rows are keyed on a marker in the reason, so re-seeding updates the
 * dates rather than stacking duplicates.
 */
class RequestWorkflowSeeder extends Seeder
{
    private const MARKER = '[demo]';

    public function run(): void
    {
        $code = static fn (string $code) => Employee::query()->where('employee_code', $code)->firstOrFail();

        // Divina Cortes: the second login-capable HR account for the demo.
        Employee::query()
            ->where('employee_code', 'ADC-0177')
            ->update(['password' => 'password']);

        $service = app(RequestWorkflowService::class);
        $today = Carbon::now(config('attendance.timezone', 'Asia/Manila'))->toDateString();

        $lito = $code('ADC-0921');      // worker on Structural crew B
        $noel = $code('ADC-0810');      // worker on Structural crew B
        $elmer = $code('ADC-0509');     // foreman of Structural crew B

        // A pending vacation leave: Lito, two weeks out, awaiting Elmer's endorsement.
        $this->demoLeave($lito, $elmer, 'vacation', Carbon::parse($today)->addDays(14), 'Annual leave, two weeks out.',
            fn (array $a) => ['status' => LeaveRequest::PENDING, 'assigned_endorser_id' => $elmer->employee_id]);

        // An endorsed sick leave: Noel, next week, Elmer has seen it, HR has not.
        $this->demoLeave($noel, $elmer, 'sick', Carbon::parse($today)->addDays(7), 'Medical follow-up leave.',
            fn (array $a) => [
                'status' => LeaveRequest::ENDORSED,
                'assigned_endorser_id' => $elmer->employee_id,
                'endorsed_by' => $elmer->employee_id,
                'endorsed_at' => Carbon::now(),
            ]);

        // A batch of three overtime nights next week under one batch key.
        $batch = 'demo-ot-'.Str::lower(Str::random(4));
        foreach ([3, 4, 5] as $offset) {
            $this->demoOvertime($lito, $elmer, Carbon::parse($today)->addDays($offset), $batch,
                fn (array $a) => ['status' => OvertimeRequest::PENDING, 'assigned_endorser_id' => $elmer->employee_id]);
        }

        // Exists so a reviewer can see an endorsed OT awaiting approval too.
        $this->demoOvertime($noel, $elmer, Carbon::parse($today)->addDays(6), $batch,
            fn (array $a) => [
                'status' => OvertimeRequest::ENDORSED,
                'assigned_endorser_id' => $elmer->employee_id,
                'endorsed_by' => $elmer->employee_id,
                'endorsed_at' => Carbon::now(),
            ]);
    }

    /** Keyed on the marker + employee + type + day, so re-seeds are updates. */
    private function demoLeave(Employee $subject, Employee $filedBy, string $type, Carbon $firstDay, string $reason, callable $workflow): void
    {
        $day = $firstDay->format('Y-m-d');
        $marker = self::MARKER.' '.$reason;

        LeaveRequest::query()->updateOrCreate(
            ['employee_id' => $subject->employee_id, 'leave_type' => $type, 'date_from' => $day, 'date_to' => $day],
            array_merge([
                'filed_by' => $filedBy->employee_id,
                'reason' => $marker,
                'status' => LeaveRequest::PENDING,
            ], $workflow([])),
        );
    }

    private function demoOvertime(Employee $subject, Employee $filedBy, Carbon $day, string $batch, callable $workflow): void
    {
        $date = $day->format('Y-m-d');
        $marker = self::MARKER.' Overtime night.';

        OvertimeRequest::query()->updateOrCreate(
            ['employee_id' => $subject->employee_id, 'ot_date' => $date, 'batch_key' => $batch],
            array_merge([
                'filed_by' => $filedBy->employee_id,
                'start_time' => '18:00',
                'end_time' => '21:30',
                'hours_requested' => 3.5,
                'reason' => $marker,
                'status' => OvertimeRequest::PENDING,
            ], $workflow([])),
        );
    }
}
