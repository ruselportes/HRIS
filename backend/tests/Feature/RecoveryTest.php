<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * Retroactive crew recovery (Phase 7 — UC-07).
 *
 * The crew was deployed on Friday 11 Sep 2026; "today" is Thursday 17 Sep.
 * Roll call exists for Fri 11, Sat 12 and Mon 14. Sunday is a rest day. So
 * Tue 15 and Wed 16 are the gaps.
 */
class RecoveryTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    private Employee $engineer;

    private Employee $hr;

    /** @var array<int, Employee> */
    private array $workers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->manila('2026-09-11', '06:00'));
        $this->setUpSignedDevice();

        $this->workers = [$this->worker(), $this->worker(), $this->worker()];
        foreach ($this->workers as $worker) {
            CrewAssignment::factory()->create([
                'crew_id' => $this->crewId,
                'employee_id' => $worker->employee_id,
                'status' => 'active',
            ]);
        }

        foreach (['2026-09-11', '2026-09-12', '2026-09-14'] as $date) {
            Attendance::query()->create([
                'employee_id' => $this->workers[0]->employee_id,
                'crew_id' => $this->crewId,
                'date' => $date,
                'status' => 'present',
                'time_in' => $this->manila($date, '06:58'),
                'sync_status' => 'synced',
            ]);
        }

        $this->engineer = $this->loginUser('engineer');
        $this->hr = $this->loginUser('hr');

        $this->travelTo($this->manila('2026-09-17', '10:00'));
    }

    public function test_working_days_with_no_roll_call_are_listed_for_recovery(): void
    {
        $response = $this->actingAs($this->engineer, 'sanctum')->getJson('/api/recovery')->assertOk();

        // Not Sun 13 (rest day), not Thu 17 (today), not before deployment,
        // not the days that have roll call.
        $this->assertSame(['2026-09-16', '2026-09-15'], collect($response->json('data'))->pluck('date')->all());
        $response
            ->assertJsonPath('data.0.stage', 'awaiting_engineer')
            ->assertJsonPath('data.0.records', 3)
            ->assertJsonPath('data.0.hours', 24)
            ->assertJsonPath('summary.to_recover', 2)
            ->assertJsonPath('summary.hours_at_risk', 48);
    }

    /** A holiday with nobody on roll call is a day off, not a gap (Phase 8 calendar). */
    public function test_a_holiday_with_no_roll_call_is_not_a_gap(): void
    {
        Holiday::query()->create(['date' => '2026-09-16', 'name' => 'Test holiday', 'type' => Holiday::SPECIAL]);

        $dates = collect($this->actingAs($this->engineer, 'sanctum')->getJson('/api/recovery')->json('data'))->pluck('date');

        $this->assertSame(['2026-09-15'], $dates->all());
    }

    public function test_the_engineer_reconstructs_the_day_as_the_first_signature(): void
    {
        $this->submit()->assertOk()
            ->assertJsonPath('data.stage', 'awaiting_hr')
            ->assertJsonPath('data.cause', 'foreman_absent')
            ->assertJsonPath('data.engineer.employee_id', $this->engineer->employee_id)
            ->assertJsonPath('data.roster.0.proposed.status', fn ($s) => in_array($s, ['present', 'late', 'absent'], true));

        $rows = Attendance::query()->where('crew_id', $this->crewId)->where('date', '2026-09-15')->get();
        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn (Attendance $a) => $a->isReconstructed()));
        // No device signed it, and HR has not yet: nothing is paid.
        $this->assertTrue($rows->every(fn (Attendance $a) => ! $a->isPayrollReady()));

        $this->assertSame(1, AuditLog::query()->where('action_type', AuditLog::RECOVERY_SUBMITTED)->count());

        $dates = collect($this->actingAs($this->engineer, 'sanctum')->getJson('/api/recovery?stage=awaiting_engineer')->json('data'))->pluck('date');
        $this->assertSame(['2026-09-16'], $dates->all());
    }

    public function test_hr_signs_off_and_payroll_can_use_it(): void
    {
        $caseId = $this->submit()->json('data.case_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/recovery/cases/{$caseId}/sign-off")
            ->assertOk()
            ->assertJsonPath('data.stage', 'closed')
            ->assertJsonPath('data.hr.employee_id', $this->hr->employee_id);

        $present = Attendance::query()->where('employee_id', $this->workers[0]->employee_id)->where('date', '2026-09-15')->sole();
        $this->assertTrue($present->isPayrollReady());
        $this->assertTrue($present->effectiveTimeIn()->eq($this->manila('2026-09-15', '07:00')));

        $late = Attendance::query()->where('employee_id', $this->workers[1]->employee_id)->where('date', '2026-09-15')->sole();
        $this->assertTrue($late->effectiveTimeIn()->eq($this->manila('2026-09-15', '08:10')));
    }

    public function test_hr_returns_it_with_a_reason_and_the_engineer_resubmits(): void
    {
        $caseId = $this->submit()->json('data.case_id');

        $this->actingAs($this->hr, 'sanctum')->postJson("/api/recovery/cases/{$caseId}/return")->assertUnprocessable();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/recovery/cases/{$caseId}/return", ['note' => 'Villamor was on leave that day.'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'returned')
            ->assertJsonPath('data.hr_note', 'Villamor was on leave that day.');

        $this->submit(firstWorker: 'not_on_crew')->assertOk()->assertJsonPath('data.stage', 'awaiting_hr');

        $this->assertFalse(
            Attendance::query()->where('employee_id', $this->workers[0]->employee_id)->where('date', '2026-09-15')->exists()
        );

        $history = $this->actingAs($this->hr, 'sanctum')
            ->getJson("/api/recovery/{$this->crewId}/2026-09-15")
            ->json('data.history');

        $this->assertSame(
            [AuditLog::RECOVERY_SUBMITTED, AuditLog::RECOVERY_RETURNED, AuditLog::RECOVERY_SUBMITTED],
            array_column($history, 'action'),
        );
    }

    public function test_each_signature_belongs_to_its_role(): void
    {
        $this->submit(by: $this->hr)->assertForbidden();
        $this->submit(by: $this->loginUser('admin'))->assertForbidden();

        $caseId = $this->submit()->json('data.case_id');

        $this->actingAs($this->engineer, 'sanctum')->postJson("/api/recovery/cases/{$caseId}/sign-off")->assertForbidden();

        foreach (['foreman', 'executive'] as $role) {
            $this->actingAs($this->loginUser($role), 'sanctum')->getJson('/api/recovery')->assertForbidden();
        }

        $this->actingAs($this->loginUser('admin'), 'sanctum')->getJson('/api/recovery')->assertOk();
    }

    public function test_a_day_with_roll_call_from_the_phone_cannot_be_reconstructed(): void
    {
        $this->submit(date: '2026-09-14')->assertUnprocessable();
    }

    public function test_today_cannot_be_reconstructed(): void
    {
        $this->submit(date: '2026-09-17')->assertUnprocessable();
    }

    public function test_every_worker_needs_one_status_and_a_time_if_they_worked(): void
    {
        $records = $this->records();

        $this->submitRaw('2026-09-15', ['cause' => 'other', 'note' => 'Logbook entries.', 'records' => array_slice($records, 0, 2)])
            ->assertUnprocessable();

        $records[1]['time_in'] = '06:50';
        $this->submitRaw('2026-09-15', ['cause' => 'other', 'note' => 'Logbook entries.', 'records' => $records])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'marked Late'));

        $records = $this->records();
        $records[0]['time_in'] = null;
        $this->submitRaw('2026-09-15', ['cause' => 'other', 'note' => 'Logbook entries.', 'records' => $records])
            ->assertUnprocessable();

        $this->submitRaw('2026-09-15', ['cause' => 'other', 'note' => 'short', 'records' => $this->records()])
            ->assertUnprocessable();
    }

    public function test_a_day_with_no_work_closes_without_records(): void
    {
        $caseId = $this->submitRaw('2026-09-16', ['cause' => 'no_work', 'note' => 'Site closed for rain, no work done.'])
            ->assertOk()
            ->json('data.case_id');

        $this->assertSame(0, Attendance::query()->where('date', '2026-09-16')->count());

        $this->actingAs($this->hr, 'sanctum')->postJson("/api/recovery/cases/{$caseId}/sign-off")->assertOk();

        $this->actingAs($this->engineer, 'sanctum')
            ->getJson('/api/recovery?stage=closed')
            ->assertJsonPath('data.0.date', '2026-09-16')
            ->assertJsonPath('data.0.hours', 0);
    }

    public function test_a_signed_off_day_cannot_be_changed(): void
    {
        $caseId = $this->submit()->json('data.case_id');
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/recovery/cases/{$caseId}/sign-off")->assertOk();

        $this->submit()->assertUnprocessable();
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/recovery/cases/{$caseId}/return", ['note' => 'Changed my mind.'])
            ->assertUnprocessable();
    }

    /** The foreman's phone chain is ordered, so a later tap proves nothing is waiting. */
    public function test_the_phone_check_reads_the_foremans_chain(): void
    {
        $this->assertSame('may_be_on_phone', $this->phoneStatus('2026-09-15'));

        $this->device->update(['last_captured_at' => $this->manila('2026-09-16', '07:02')->getTimestampMs()]);
        $this->assertSame('nothing_waiting', $this->phoneStatus('2026-09-15'));
        $this->assertSame('may_be_on_phone', $this->phoneStatus('2026-09-16'));

        $this->device->update(['revoked_at' => now()]);
        $this->assertSame('no_phone', $this->phoneStatus('2026-09-15'));
    }

    /** Captured and signed on the phone beats reconstructed from memory. */
    public function test_roll_call_arriving_from_the_phone_later_replaces_the_reconstruction(): void
    {
        $this->submit()->assertOk();

        $tapped = $this->manila('2026-09-15', '07:05')->getTimestampMs();
        $this->sync($this->buildBatch([$this->workers[0]->employee_id], null, [[
            'date' => '2026-09-15',
            'time_in' => $tapped,
            'captured_at' => $tapped,
        ]]))->assertOk()->assertJsonPath('accepted', 1);

        $row = Attendance::query()->where('employee_id', $this->workers[0]->employee_id)->where('date', '2026-09-15')->sole();
        $this->assertFalse($row->isReconstructed());
        $this->assertNull($row->override_audit_id);
        $this->assertTrue($row->isPayrollReady());
    }

    private function submit(string $date = '2026-09-15', ?Employee $by = null, string $firstWorker = 'present')
    {
        $records = $this->records();
        $records[0]['status'] = $firstWorker;
        $records[0]['time_in'] = $firstWorker === 'present' ? '07:00' : null;

        return $this->submitRaw($date, [
            'cause' => 'foreman_absent',
            'note' => 'Foreman absent, no acting foreman assigned. Rebuilt from the site logbook.',
            'records' => $records,
        ], $by);
    }

    private function submitRaw(string $date, array $payload, ?Employee $by = null)
    {
        return $this->actingAs($by ?? $this->engineer, 'sanctum')
            ->postJson("/api/recovery/{$this->crewId}/{$date}", $payload);
    }

    private function records(): array
    {
        return [
            ['employee_id' => $this->workers[0]->employee_id, 'status' => 'present', 'time_in' => '07:00'],
            ['employee_id' => $this->workers[1]->employee_id, 'status' => 'late', 'time_in' => '08:10'],
            ['employee_id' => $this->workers[2]->employee_id, 'status' => 'absent', 'time_in' => null],
        ];
    }

    private function phoneStatus(string $date): string
    {
        return $this->actingAs($this->engineer, 'sanctum')
            ->getJson("/api/recovery/{$this->crewId}/{$date}")
            ->assertOk()
            ->json('data.phone.status');
    }

    private function manila(string $date, string $time): Carbon
    {
        return Carbon::parse("{$date} {$time}", 'Asia/Manila');
    }
}
