<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrewCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engineer = $this->loginUser('engineer', ['site_id' => $this->site()->site_id]);
        $this->foremanUser = $this->loginUser('foreman');
        $this->hr = $this->loginUser('hr', ['employment_status' => 'regular']);
    }

    private function fieldWorker(string $roleSlug = 'worker', array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'role_id' => $this->role($roleSlug)->role_id,
            'site_id' => $this->site()->site_id,
            'employment_status' => 'regular',
        ], $overrides));
    }

    private function createdCrew(array $overrides = []): Crew
    {
        return Crew::factory()->create(array_merge([
            'site_id' => $this->site()->site_id,
            'crew_name' => 'Rebar crew Z',
            'status' => 'draft',
        ], $overrides));
    }

    public function test_engineer_can_create_draft_crew(): void
    {
        $response = $this->actingAs($this->engineer, 'sanctum')
            ->postJson('/api/crews', ['site_id' => $this->site()->site_id, 'crew_name' => 'Rebar crew Z'])
            ->assertCreated();

        $this->assertSame('draft', $response->json('data.status'));
        $this->assertNull($response->json('data.deployed_at'));
        $this->assertSame('Rebar crew Z', $response->json('data.crew_name'));
    }

    public function test_engineer_can_designate_foreman(): void
    {
        $crew = $this->createdCrew();

        $this->actingAs($this->engineer, 'sanctum')
            ->putJson("/api/crews/{$crew->crew_id}/foreman", ['foreman_id' => $this->foremanUser->employee_id])
            ->assertOk()
            ->assertJsonPath('data.foreman.employee_id', $this->foremanUser->employee_id);

        $this->assertDatabaseHas('crews', ['crew_id' => $crew->crew_id, 'foreman_id' => $this->foremanUser->employee_id]);
    }

    public function test_foreman_designation_rejects_non_foreman_role(): void
    {
        $worker = $this->fieldWorker();
        $crew = $this->createdCrew();

        $this->actingAs($this->engineer, 'sanctum')
            ->putJson("/api/crews/{$crew->crew_id}/foreman", ['foreman_id' => $worker->employee_id])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'does not hold the Site Foreman role'));
    }

    public function test_engineer_can_assign_and_remove_members(): void
    {
        $crew = $this->createdCrew();
        $w1 = $this->fieldWorker();
        $w2 = $this->fieldWorker();

        $added = $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/crews/{$crew->crew_id}/members", ['employee_ids' => [$w1->employee_id, $w2->employee_id]])
            ->assertOk();

        $this->assertCount(2, $added->json('data.members'));

        $this->actingAs($this->engineer, 'sanctum')
            ->deleteJson("/api/crews/{$crew->crew_id}/members/{$w1->employee_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.members');

        $this->assertDatabaseHas('crew_assignments', [
            'crew_id' => $crew->crew_id,
            'employee_id' => $w1->employee_id,
            'status' => 'inactive',
        ]);
    }

    public function test_assign_rejects_worker_already_active_in_another_crew(): void
    {
        $crewA = $this->createdCrew(['crew_name' => 'Crew A']);
        $crewB = $this->createdCrew(['crew_name' => 'Crew B']);
        $worker = $this->fieldWorker();

        CrewAssignment::factory()->create([
            'crew_id' => $crewA->crew_id,
            'employee_id' => $worker->employee_id,
        ]);

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/crews/{$crewB->crew_id}/members", ['employee_ids' => [$worker->employee_id]])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'already active in Crew A'));
    }

    public function test_assign_rejects_non_field_worker(): void
    {
        $crew = $this->createdCrew();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/crews/{$crew->crew_id}/members", ['employee_ids' => [$this->engineer->employee_id]])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'is not a field worker'));
    }

    public function test_cannot_deploy_crew_without_foreman(): void
    {
        $crew = $this->createdCrew();
        $worker = $this->fieldWorker();
        $crew->assignments()->create(['employee_id' => $worker->employee_id, 'status' => 'active']);

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/crews/{$crew->crew_id}/deploy")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Assign a foreman before deploying this crew.');

        $this->assertDatabaseHas('crews', ['crew_id' => $crew->crew_id, 'status' => 'draft']);
    }

    public function test_deploy_stamps_crew_and_members_when_foreman_assigned(): void
    {
        $crew = $this->createdCrew();
        $worker = $this->fieldWorker();
        $crew->assignments()->create(['employee_id' => $worker->employee_id, 'status' => 'active']);
        $crew->update(['foreman_id' => $this->foremanUser->employee_id]);

        $response = $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/crews/{$crew->crew_id}/deploy", ['effective_date' => '2026-09-07'])
            ->assertOk();

        $this->assertSame('deployed', $response->json('data.status'));
        $this->assertNotNull($response->json('data.deployed_at'));

        $this->assertDatabaseHas('crew_assignments', [
            'crew_id' => $crew->crew_id,
            'employee_id' => $worker->employee_id,
            'date_assigned' => '2026-09-07',
        ]);
        $this->assertDatabaseHas('crews', ['crew_id' => $crew->crew_id, 'status' => 'deployed']);
    }

    public function test_pool_excludes_assigned_and_separated_workers(): void
    {
        $available = $this->fieldWorker('worker', ['employee_code' => 'ADC-9101']);
        $assigned = $this->fieldWorker('worker', ['employee_code' => 'ADC-9102']);
        $separated = $this->fieldWorker('worker', ['employee_code' => 'ADC-9103', 'employment_status' => 'separated']);

        $crew = $this->createdCrew();
        CrewAssignment::factory()->create(['crew_id' => $crew->crew_id, 'employee_id' => $assigned->employee_id]);

        $response = $this->actingAs($this->engineer, 'sanctum')
            ->getJson('/api/crews/pool')
            ->assertOk();

        $codes = collect($response->json('pool'))->pluck('employee_code')->all();
        $this->assertContains('ADC-9101', $codes);
        $this->assertNotContains('ADC-9102', $codes);
        $this->assertNotContains('ADC-9103', $codes);
    }

    public function test_pool_blocks_workers_with_expired_certification(): void
    {
        $worker = $this->fieldWorker();
        $worker->update(['certification' => [
            ['name' => 'Safety', 'expires_at' => '2020-01-01'],
        ]]);

        $row = collect($this->actingAs($this->engineer, 'sanctum')->getJson('/api/crews/pool')->json('pool'))
            ->first(fn ($r) => $r['employee_id'] === $worker->employee_id);

        $this->assertSame('expired', $row['cert_status']);
        $this->assertSame('expired_cert', $row['blocked_by']);
    }

    public function test_hr_can_view_but_not_manage_crews(): void
    {
        $this->createdCrew();

        $this->actingAs($this->hr, 'sanctum')
            ->getJson('/api/crews')
            ->assertOk();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/crews', ['site_id' => $this->site()->site_id, 'crew_name' => 'Blocked'])
            ->assertForbidden();
    }

    public function test_foreman_has_no_crew_builder_access(): void
    {
        $this->actingAs($this->foremanUser, 'sanctum')
            ->getJson('/api/crews')
            ->assertForbidden();
    }

    public function test_deployment_overview_returns_summary_stats(): void
    {
        $crew = $this->createdCrew();
        $worker = $this->fieldWorker();
        $crew->update(['foreman_id' => $this->foremanUser->employee_id]);
        $crew->assignments()->create(['employee_id' => $worker->employee_id, 'status' => 'active']);
        $crew->update(['status' => 'deployed', 'deployed_at' => now()]);

        $this->createdCrew(['crew_name' => 'Draft crew X']);

        $response = $this->actingAs($this->engineer, 'sanctum')
            ->getJson('/api/deployment')
            ->assertOk();

        $this->assertSame(1, $response->json('deployed_workers'));
        $this->assertSame(1, $response->json('crews_without_foreman'));
        $this->assertCount(1, $response->json('sites'));
        $this->assertCount(2, $response->json('sites.0.crews'));
        $this->assertContains('Rebar crew Z', collect($response->json('sites.0.crews'))->pluck('crew_name')->all());
        $this->assertArrayHasKey('pool_available', $response->json());
    }
}
