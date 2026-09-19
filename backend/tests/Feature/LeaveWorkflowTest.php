<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * Leave & Overtime Filing and Approval (Phase 9 — UC-10), the leave half.
 *
 * "Today" is frozen at Manila time 2026-09-17 (a Thursday): 22–25 and 28–29
 * Sep are forward dates, 10–11 Sep are in the past, and the retro-leave rules
 * are exercised against those boundaries.
 */
class LeaveWorkflowTest extends TestCase
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

    public function test_foreman_files_for_a_crew_member_and_it_lands_with_his_own_endorsement(): void
    {
        $worker = $this->crewMember();

        $response = $this->actingAs($this->foreman, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $worker->employee_id,
            'leave_type' => 'vacation',
            'reason' => 'Family trip',
            'date_from' => '2026-09-22',
            'date_to' => '2026-09-23',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.assigned_endorser.employee_id', $this->foreman->employee_id)
            ->assertJsonPath('data.employee.employee_id', $worker->employee_id);

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => AuditLog::REQUEST_SUBMITTED,
            'description' => "Leave request #{$response->json('data.leave_id')} filed for {$worker->full_name} "
                ."vacation 2026-09-22–2026-09-23. Endorser: {$this->foreman->full_name}.",
        ]);
    }

    public function test_a_foreman_cannot_file_for_someone_outside_his_crew(): void
    {
        $outsider = $this->worker();
        $otherForeman = $this->loginUser('foreman');
        $otherCrew = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $otherForeman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);
        $this->memberOf($otherCrew->crew_id, $outsider);

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $outsider->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Not my worker.',
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-22',
            ])
            ->assertForbidden();
    }

    public function test_retrospective_leave_may_only_be_sick_leave(): void
    {
        $worker = $this->crewMember();

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'vacation',
                'reason' => 'Going back before today.',
                'date_from' => '2026-09-11',
                'date_to' => '2026-09-11',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Retrospective leave (before today) may only be sick leave; 2026-09-11 is in the past.');
    }

    public function test_retrospective_sick_leave_refuses_a_clocked_day_but_allows_a_quiet_day(): void
    {
        $worker = $this->crewMember();

        Attendance::query()->create([
            'employee_id' => $worker->employee_id,
            'crew_id' => $this->crewId,
            'date' => '2026-09-11',
            'status' => 'present',
            'time_in' => $this->manila('2026-09-11', '06:58'),
            'sync_status' => 'synced',
        ]);

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Sick before leave had started.',
                'date_from' => '2026-09-11',
                'date_to' => '2026-09-11',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$worker->full_name} was clocked in on 2026-09-11; a retrospective leave cannot rewrite it.");

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Sick since 10 Sep.',
                'date_from' => '2026-09-10',
                'date_to' => '2026-09-10',
            ])
            ->assertCreated();
    }

    public function test_leave_is_refused_when_a_forward_date_already_has_approved_overtime(): void
    {
        $worker = $this->crewMember();

        OvertimeRequest::query()->create([
            'employee_id' => $worker->employee_id,
            'filed_by' => $this->foreman->employee_id,
            'ot_date' => '2026-09-22',
            'start_time' => '18:00',
            'end_time' => '21:30',
            'status' => OvertimeRequest::APPROVED,
            'approved_by' => $this->hr->employee_id,
            'approved_at' => now(),
        ]);

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'vacation',
                'reason' => 'Holiday trip.',
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-23',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$worker->full_name} already has approved overtime on 2026-09-22; a leave cannot cover it.");
    }

    public function test_only_the_assigned_endorser_can_endorse_and_only_while_pending(): void
    {
        $worker = $this->crewMember();
        $otherForeman = $this->loginUser('foreman');

        $leaveId = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Doctor.',
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-22',
            ])
            ->json('data.leave_id');

        $this->actingAs($otherForeman, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/endorse")
            ->assertForbidden();

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/endorse")
            ->assertOk()
            ->assertJsonPath('data.status', 'endorsed')
            ->assertJsonPath('data.endorsed_by.employee_id', $this->foreman->employee_id);

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => AuditLog::REQUEST_ENDORSED,
            'actor_id' => $this->foreman->employee_id,
        ]);

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/endorse")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Leave request #{$leaveId} is not pending; only a pending request can be endorsed.");
    }

    public function test_hr_cannot_skip_endorsement_when_one_is_assigned(): void
    {
        $worker = $this->crewMember();

        $leaveId = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Doctor.',
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-22',
            ])
            ->json('data.leave_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Leave request #{$leaveId} is still with its endorser; it must be endorsed first.");
    }

    public function test_happy_path_endorse_then_hr_approves_and_approved_is_final(): void
    {
        $worker = $this->crewMember();

        $leaveId = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Doctor.',
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-22',
            ])
            ->json('data.leave_id');

        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/leaves/{$leaveId}/endorse")->assertOk();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by.employee_id', $this->hr->employee_id);

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => AuditLog::REQUEST_APPROVED,
            'actor_id' => $this->hr->employee_id,
        ]);

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Leave request #{$leaveId} is already decided; nothing left to approve.");
    }

    public function test_hr_can_approve_a_pending_request_with_no_assigned_endorser_directly(): void
    {
        $worker = $this->worker();

        $response = $this->actingAs($this->hr, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $worker->employee_id,
            'leave_type' => 'sick',
            'reason' => 'Medical certificate.',
            'date_from' => '2026-09-22',
            'date_to' => '2026-09-22',
        ]);

        $response->assertCreated()->assertJsonPath('data.assigned_endorser', null);

        $secondHr = $this->loginUser('hr');

        $this->actingAs($secondHr, 'sanctum')
            ->postJson("/api/leaves/{$response->json('data.leave_id')}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_the_approver_cannot_be_the_endorser(): void
    {
        $worker = $this->crewMember();
        $secondHr = $this->loginUser('hr');

        $leaveId = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Doctor.',
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-22',
            ])
            ->json('data.leave_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/reassign-endorser", ['employee_id' => $secondHr->employee_id])
            ->assertOk();

        $this->actingAs($secondHr, 'sanctum')->postJson("/api/leaves/{$leaveId}/endorse")->assertOk();

        $this->actingAs($secondHr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The approver of a request cannot be its subject, filer, or endorser.');
    }

    public function test_the_filer_cancels_only_pending_and_only_their_own(): void
    {
        $worker = $this->crewMember();

        $leaveId = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Doctor.',
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-22',
            ])
            ->json('data.leave_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/cancel")
            ->assertForbidden();

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => AuditLog::REQUEST_CANCELLED,
            'actor_id' => $this->foreman->employee_id,
        ]);

        $second = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'vacation',
                'reason' => 'Family.',
                'date_from' => '2026-09-24',
                'date_to' => '2026-09-25',
            ])
            ->json('data.leave_id');
        $this->actingAs($this->foreman, 'sanctum')->postJson("/api/leaves/{$second}/endorse")->assertOk();

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson("/api/leaves/{$second}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only a pending request can be cancelled.');
    }

    public function test_rejection_needs_a_note_and_records_it(): void
    {
        $worker = $this->crewMember();

        $leaveId = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'vacation',
                'reason' => 'Peak season.',
                'date_from' => '2026-09-28',
                'date_to' => '2026-09-29',
            ])
            ->json('data.leave_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/reject")
            ->assertUnprocessable();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/reject", ['rejection_note' => 'No cover available those days.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_note', 'No cover available those days.')
            ->assertJsonPath('data.rejected_by.employee_id', $this->hr->employee_id);
    }

    public function test_hr_can_reassign_the_endorser_but_only_while_pending(): void
    {
        $worker = $this->crewMember();
        $replacement = $this->loginUser('foreman');

        $leaveId = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Doctor.',
                'date_from' => '2026-09-23',
                'date_to' => '2026-09-23',
            ])
            ->json('data.leave_id');

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/reassign-endorser", ['employee_id' => $replacement->employee_id])
            ->assertOk()
            ->assertJsonPath('data.assigned_endorser.employee_id', $replacement->employee_id);

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => AuditLog::REQUEST_ENDORSER_REASSIGNED,
            'actor_id' => $this->hr->employee_id,
            'description' => "Leave request #{$leaveId} endorser changed by {$this->hr->full_name} "
                ."from {$this->foreman->full_name} to {$replacement->full_name}.",
        ]);

        $this->actingAs($replacement, 'sanctum')->postJson("/api/leaves/{$leaveId}/endorse")->assertOk();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$leaveId}/reassign-endorser", ['employee_id' => $this->loginUser('foreman')->employee_id])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Only a pending request can change endorsers; Leave request #{$leaveId} is already endorsed.");

        $fresh = $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'sick',
                'reason' => 'Doctor.',
                'date_from' => '2026-09-24',
                'date_to' => '2026-09-24',
            ])
            ->json('data.leave_id');
        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/leaves/{$fresh}/reassign-endorser", ['employee_id' => $worker->employee_id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The endorser cannot be the subject or the filer of the request.');
    }

    public function test_endorser_resolution_falls_back_to_a_site_engineer_picking_the_lowest_id(): void
    {
        $site = $this->site();
        $worker = $this->worker();

        $engineerA = $this->loginUser('engineer', ['site_id' => $site->site_id]);
        $engineerB = $this->loginUser('engineer', ['site_id' => $site->site_id]);
        $this->loginUser('engineer', ['site_id' => $this->site('Site 99 — LeaveWorkflowTest')->site_id]);
        $lowest = min($engineerA->employee_id, $engineerB->employee_id);

        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/leaves', [
                'employee_id' => $worker->employee_id,
                'leave_type' => 'vacation',
                'reason' => 'Family.',
                'date_from' => '2026-09-25',
                'date_to' => '2026-09-26',
            ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_endorser.employee_id', $lowest);
    }

    public function test_read_scope_hides_other_crews_from_a_foreman_and_other_sites_from_an_engineer(): void
    {
        $site = $this->site();
        $myWorker = $this->crewMember('my crew');

        // Another foreman's crew on the same site, with its own request.
        $otherForeman = $this->loginUser('foreman');
        $otherCrew = Crew::factory()->create([
            'site_id' => $site->site_id,
            'foreman_id' => $otherForeman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);
        $otherWorker = $this->worker();
        $this->memberOf($otherCrew->crew_id, $otherWorker);

        $mine = $this->actingAs($this->foreman, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $myWorker->employee_id,
            'leave_type' => 'sick',
            'reason' => 'Doctor.',
            'date_from' => '2026-09-22',
            'date_to' => '2026-09-22',
        ])->json('data.leave_id');
        $theirs = $this->actingAs($otherForeman, 'sanctum')->postJson('/api/leaves', [
            'employee_id' => $otherWorker->employee_id,
            'leave_type' => 'vacation',
            'reason' => 'Family.',
            'date_from' => '2026-09-23',
            'date_to' => '2026-09-23',
        ])->json('data.leave_id');

        $this->actingAs($this->foreman, 'sanctum')->getJson('/api/leaves')
            ->assertOk()
            ->assertJsonPath('data.0.leave_id', $mine)
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->foreman, 'sanctum')->getJson("/api/leaves/{$theirs}")->assertNotFound();

        $this->actingAs($this->hr, 'sanctum')->getJson('/api/leaves')->assertJsonCount(2, 'data');

        $engineer = $this->loginUser('engineer', ['site_id' => $site->site_id]);
        $siteNames = collect($this->actingAs($engineer, 'sanctum')->getJson('/api/leaves')->json('data'))
            ->pluck('employee.site');
        $this->assertTrue($siteNames->every(fn ($name) => $name === $site->site_name), "engineer saw {$siteNames->implode(', ')}");
    }

    public function test_admin_has_no_leave_access_at_all(): void
    {
        $this->actingAs($this->loginUser('admin'), 'sanctum')
            ->getJson('/api/leaves')
            ->assertForbidden();
    }

    private function crewMember(string $label = 'crew'): Employee
    {
        $worker = $this->worker();
        $this->memberOf($this->crewId, $worker);

        return $worker;
    }

    private function memberOf(int $crewId, Employee $worker): void
    {
        CrewAssignment::factory()->create([
            'crew_id' => $crewId,
            'employee_id' => $worker->employee_id,
            'status' => 'active',
        ]);
    }

    private function manila(string $date, string $time): Carbon
    {
        return Carbon::parse("{$date} {$time}", config('attendance.timezone', 'Asia/Manila'));
    }
}
