<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForemanCrewTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreman_sees_only_their_own_deployed_crew_with_active_roster(): void
    {
        $foreman = $this->loginUser('foreman');
        $otherForeman = $this->loginUser('foreman');

        $crew = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $foreman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);

        $worker = Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site()->site_id,
        ]);

        CrewAssignment::factory()->create([
            'crew_id' => $crew->crew_id,
            'employee_id' => $worker->employee_id,
            'status' => 'active',
        ]);

        $removedWorker = Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site()->site_id,
        ]);

        CrewAssignment::factory()->create([
            'crew_id' => $crew->crew_id,
            'employee_id' => $removedWorker->employee_id,
            'status' => 'removed',
        ]);

        // Another foreman's own deployed crew — must not leak into the response.
        Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $otherForeman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/me/crew')
            ->assertOk();

        $response->assertJsonPath('crew.crew_id', $crew->crew_id);
        $response->assertJsonCount(1, 'crew.members');
        $response->assertJsonPath('crew.members.0.employee_id', $worker->employee_id);
    }

    public function test_foreman_with_no_deployed_crew_gets_null(): void
    {
        $foreman = $this->loginUser('foreman');

        // A draft (not deployed) crew for this foreman should not surface.
        Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $foreman->employee_id,
            'status' => 'draft',
        ]);

        $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/me/crew')
            ->assertOk()
            ->assertJsonPath('crew', null);
    }

    public function test_non_foreman_roles_are_forbidden(): void
    {
        $hr = $this->loginUser('hr');

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/me/crew')
            ->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/me/crew')->assertUnauthorized();
    }
}
