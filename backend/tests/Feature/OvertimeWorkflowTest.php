<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
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

    public function test_batch_approve_approves_the_ready_and_skips_the_rest_with_reasons(): void
    {
        $worker = $this->crewMember();

        $ready = $this->fileFor($worker, '2026-09-22', ['batch_key' => 'demo-ot-week'])->json('data.ot_id');
        $notEndorsed = $this->fileFor($worker, '2026-09-23', ['batch_key' => 'demo-ot-week'])->json('data.ot_id');
        $decided = $this->fileFor($worker, '2026-09-24', ['batch_key' => 'demo-ot-week'])->json('data.ot_id');

        // Cancel a third before endorsing, so an approve can never reopen it;
        // endorsing the first carries the still-pending sibling with it.
        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/overtimes/{$decided}/cancel")->assertOk();
        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/overtimes/{$ready}/endorse")->assertOk();

        $response = $this->actingAs($this->hr, 'sanctum')->postJson('/api/overtimes/batch-approve', [
            'ot_ids' => [$ready, $notEndorsed, $decided],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.approved', [$ready, $notEndorsed])
            ->assertJsonPath('data.skipped.0.id', $decided)
            ->assertJsonPath('data.skipped.0.reason', "Overtime request #{$decided} is already decided; nothing left to approve.");

        $this->assertSame(OvertimeRequest::APPROVED, OvertimeRequest::find($ready)->status);
        $this->assertSame(OvertimeRequest::APPROVED, OvertimeRequest::find($notEndorsed)->status);
        $this->assertSame(OvertimeRequest::CANCELLED, OvertimeRequest::find($decided)->status);
    }

    public function test_batch_approve_returns_422_when_none_can_be_approved(): void
    {
        $worker = $this->crewMember();

        $a = $this->fileFor($worker, '2026-09-22', ['batch_key' => 'week-3'])->json('data.ot_id');
        $b = $this->fileFor($worker, '2026-09-23', ['batch_key' => 'week-3'])->json('data.ot_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/overtimes/batch-approve', ['ot_ids' => [$a, $b]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'None of the 2 overtime requests could be approved.')
            ->assertJsonCount(2, 'data.skipped');
    }

    public function test_batch_approve_refuses_an_unknown_id(): void
    {
        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/overtimes/batch-approve', ['ot_ids' => [999_999]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unknown overtime request: #999999.');
    }

    public function test_batch_approve_requires_ids_to_be_an_array_of_integers(): void
    {
        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/overtimes/batch-approve', ['ot_ids' => 'one'])
            ->assertUnprocessable();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/overtimes/batch-approve', ['ot_ids' => [1, 1]])
            ->assertUnprocessable();

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/overtimes/batch-approve', ['ot_ids' => [1]])
            ->assertForbidden();
    }

    public function test_approving_overtime_inside_an_approved_payroll_period_is_refused(): void
    {
        $this->travelTo($this->manila('2026-08-21', '10:00'));

        $worker = $this->crewMember();
        $otId = $this->actingAs($this->foreman, 'sanctum')->postJson('/api/overtimes', [
            'employee_id' => $worker->employee_id,
            'ot_date' => '2026-08-25',
            'start_time' => '18:00',
            'end_time' => '21:30',
        ])->json('data.ot_id');

        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/overtimes/{$otId}/endorse")->assertOk();

        // The period closes before HR approves the night.
        Payroll::query()->create([
            'employee_id' => $worker->employee_id,
            'run_code' => '2026-09-A',
            'pay_period_start' => '2026-08-21',
            'pay_period_end' => '2026-09-05',
            'gross_pay' => '5000.00',
            'net_pay' => '4500.00',
            'status' => Payroll::APPROVED,
        ]);

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/overtimes/{$otId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Overtime request #{$otId} lies inside approved payroll 2026-09-A; a closed period is re-opened in payroll, not by approval.");

        $this->assertSame(OvertimeRequest::ENDORSED, OvertimeRequest::find($otId)->status);

        // And the bulk path reports it as an individual skip, not a failure.
        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/overtimes/batch-approve', ['ot_ids' => [$otId]])
            ->assertUnprocessable()
            ->assertJsonPath('data.skipped.0.reason', "Overtime request #{$otId} lies inside approved payroll 2026-09-A; a closed period is re-opened in payroll, not by approval.");
    }

    public function test_endorsing_and_approving_cascade_to_siblings_of_the_same_batch(): void
    {
        $worker = $this->crewMember();

        $a = $this->fileFor($worker, '2026-09-22', ['batch_key' => 'pour-week'])->json('data.ot_id');
        $b = $this->fileFor($worker, '2026-09-23', ['batch_key' => 'pour-week'])->json('data.ot_id');
        $c = $this->fileFor($worker, '2026-09-24', ['batch_key' => 'pour-week'])->json('data.ot_id');

        // One endorse carries the batch; one approve carries the batch again.
        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/overtimes/{$a}/endorse")->assertOk();
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/overtimes/{$a}/approve")->assertOk();

        foreach ([$a, $b, $c] as $id) {
            $this->assertSame(OvertimeRequest::APPROVED, OvertimeRequest::find($id)->status);
        }
    }

    public function test_cascade_leaves_a_foreign_batch_template_alone_and_a_cancel_rejects_follow(): void
    {
        $worker = $this->crewMember();

        $owned = $this->fileFor($worker, '2026-09-22', ['batch_key' => 'mix-week'])->json('data.ot_id');
        $foreign = $this->fileFor($worker, '2026-09-23', ['batch_key' => 'mix-week'])->json('data.ot_id');
        $cancelled = $this->fileFor($worker, '2026-09-24', ['batch_key' => 'mix-week'])->json('data.ot_id');

        $otherForeman = $this->loginUser('foreman');
        OvertimeRequest::find($foreign)->update(['assigned_endorser_id' => $otherForeman->employee_id]);
        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/overtimes/{$cancelled}/cancel")->assertOk();

        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/overtimes/{$owned}/endorse")->assertOk();
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/overtimes/{$owned}/approve")->assertOk();

        $this->assertSame(OvertimeRequest::APPROVED, OvertimeRequest::find($owned)->status);
        $this->assertSame(OvertimeRequest::PENDING, OvertimeRequest::find($foreign)->status);
        $this->assertSame(OvertimeRequest::CANCELLED, OvertimeRequest::find($cancelled)->status);
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
