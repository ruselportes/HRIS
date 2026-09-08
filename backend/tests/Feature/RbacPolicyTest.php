<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function worker(string $roleSlug, array $overrides = []): Employee
    {
        return $this->loginUser($roleSlug, array_merge(['employee_code' => 'ADC-'.rand(5000, 5999)], $overrides));
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/employees')->assertUnauthorized();
        $this->getJson('/api/roles')->assertUnauthorized();
        $this->getJson('/api/sites')->assertUnauthorized();
    }

    public function test_foreman_cannot_view_employee_registry(): void
    {
        $foreman = $this->worker('foreman');

        $this->actingAs($foreman, 'sanctum')->getJson('/api/employees')->assertForbidden();
    }

    public function test_foreman_cannot_create_employees(): void
    {
        $foreman = $this->worker('foreman');

        $this->actingAs($foreman, 'sanctum')->postJson('/api/employees', $this->validPayload())
            ->assertForbidden();
    }

    public function test_engineer_can_view_but_not_create_or_update(): void
    {
        $engineer = $this->worker('engineer');
        $subject = $this->worker('worker');

        $this->actingAs($engineer, 'sanctum')->getJson('/api/employees')->assertOk();

        $this->actingAs($engineer, 'sanctum')
            ->postJson('/api/employees', $this->validPayload())
            ->assertForbidden();

        $this->actingAs($engineer, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['first_name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_executive_can_view_but_not_edit(): void
    {
        $executive = $this->worker('executive');
        $subject = $this->worker('worker');

        $this->actingAs($executive, 'sanctum')->getJson('/api/employees')->assertOk();

        $this->actingAs($executive, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['first_name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_hr_can_create_update_and_list(): void
    {
        $hr = $this->worker('hr');

        $created = $this->actingAs($hr, 'sanctum')
            ->postJson('/api/employees', $this->validPayload())
            ->assertCreated();

        $id = $created->json('data.employee_id');

        $this->actingAs($hr, 'sanctum')->getJson('/api/employees')->assertOk();

        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/employees/{$id}", ['first_name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.first_name', 'Renamed');
    }

    public function test_admin_can_create_employees(): void
    {
        $admin = $this->worker('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/employees', $this->validPayload())
            ->assertCreated();
    }

    public function test_next_code_route_is_hr_admin_only(): void
    {
        $foreman = $this->worker('foreman');
        $hr = $this->worker('hr');

        $this->actingAs($foreman, 'sanctum')->getJson('/api/employees/next-code')->assertForbidden();

        $this->actingAs($hr, 'sanctum')->getJson('/api/employees/next-code')->assertOk();
    }

    public function test_no_employee_can_be_deleted(): void
    {
        $hr = $this->worker('hr');
        $subject = $this->worker('worker');

        $this->actingAs($hr, 'sanctum')
            ->deleteJson("/api/employees/{$subject->employee_id}")
            ->assertStatus(405);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site()->site_id,
            'first_name' => 'New',
            'last_name' => 'Employee',
            'date_of_birth' => '1995-01-01',
            'mobile' => '+63 917 555 0101',
            'date_hired' => '2026-09-01',
            'employment_status' => 'probationary',
            'daily_rate' => 820.00,
            'cost_centre' => 'CO-04',
        ], $overrides);
    }
}
