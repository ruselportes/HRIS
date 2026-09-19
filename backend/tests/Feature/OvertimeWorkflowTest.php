<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * Leave & Overtime Filing and Approval (Phase 9 — UC-10), the overtime half.
 * Filing, endorsement and approval share RequestWorkflowService with leaves;
 * this covers what is distinct: the past-date guard, the leave-coverage guard,
 * the batch field, and the foreman's read scope.
 */
class OvertimeWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    private Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->manila('2026-09-17', '10:00'));
        $this->setUpSignedDevice();
        $this->hr = $this->loginUser('hr');
    }

    public function test_happy_path_file_endorse_approve_with_a_batch_key(): void
    {
        $response = $this->fileFor($this->crewMember(), '2026-09-22', ['batch_key' => 'demo-ot-week', 'hours_requested' => 3.5]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.batch_key', 'demo-ot-week')
            ->assertJsonPath('data.assigned_endorser.employee_id', $this->foreman->employee_id);

        $otId = $response->json('data.ot_id');

        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/overtimes/{$otId}/endorse")->assertOk();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/overtimes/{$otId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by.employee_id', $this->hr->employee_id);
    }

    public function test_overtime_is_refused_for_a_past_date(): void
    {
        $this->fileFor($this->crewMember(), '2026-09-11')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Overtime cannot be filed for a past date; 2026-09-11 is already gone.');
    }

    public function test_overtime_is_refused_when_an_approved_leave_covers_the_date(): void
    {
        $worker = $this->crewMember();

        LeaveRequest::query()->create([
            'employee_id' => $worker->employee_id,
            'filed_by' => $this->foreman->employee_id,
            'leave_type' => 'vacation',
            'reason' => 'Family.',
            'date_from' => '2026-09-22',
            'date_to' => '2026-09-24',
            'status' => LeaveRequest::APPROVED,
            'approved_by' => $this->hr->employee_id,
            'approved_at' => now(),
        ]);

        $this->fileFor($worker, '2026-09-23')
            ->assertUnprocessable()
            ->assertJsonPath('message', '2026-09-23 is covered by an approved leave; overtime cannot be filed on it.');
    }

    public function test_a_foreman_sees_only_his_own_crews_overtimes_and_his_own_filings(): void
    {
        $myWorker = $this->crewMember();
        $myOt = $this->fileFor($myWorker, '2026-09-22')->json('data.ot_id');

        // A request on a crew this foreman does not lead.
        $otherForeman = $this->loginUser('foreman');
        $otherCrew = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $otherForeman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);
        $otherWorker = $this->worker();
        CrewAssignment::factory()->create([
            'crew_id' => $otherCrew->crew_id,
            'employee_id' => $otherWorker->employee_id,
            'status' => 'active',
        ]);
        $theirOt = $this->actingAs($otherForeman, 'sanctum')->postJson('/api/overtimes', [
            'employee_id' => $otherWorker->employee_id,
            'ot_date' => '2026-09-23',
            'start_time' => '18:00',
            'end_time' => '21:30',
        ])->json('data.ot_id');

        $this->actingAs($this->foreman, 'sanctum')->getJson('/api/overtimes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ot_id', $myOt);

        $this->actingAs($this->foreman, 'sanctum')->getJson("/api/overtimes/{$theirOt}")->assertNotFound();
    }

    private function fileFor(Employee $worker, string $date, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->foreman, 'sanctum')->postJson('/api/overtimes', array_merge([
            'employee_id' => $worker->employee_id,
            'ot_date' => $date,
            'start_time' => '18:00',
            'end_time' => '21:30',
            'reason' => 'Night pouring.',
        ], $overrides));
    }

    private function crewMember(): Employee
    {
        $worker = $this->worker();
        CrewAssignment::factory()->create([
            'crew_id' => $this->crewId,
            'employee_id' => $worker->employee_id,
            'status' => 'active',
        ]);

        return $worker;
    }

    private function manila(string $date, string $time): Carbon
    {
        return Carbon::parse("{$date} {$time}", config('attendance.timezone', 'Asia/Manila'));
    }
}
