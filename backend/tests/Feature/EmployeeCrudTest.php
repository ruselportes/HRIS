<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->loginUser('hr', [
            'employee_code' => 'ADC-4001',
            'employment_status' => 'probationary',
        ]);
    }

    public function test_store_requires_core_fields(): void
    {
        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/employees', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'last_name', 'first_name', 'date_of_birth', 'mobile',
                'role_id', 'site_id', 'date_hired', 'employment_status',
                'daily_rate', 'cost_centre',
            ]);
    }

    public function test_daily_rate_below_regional_minimum_is_rejected(): void
    {
        $response = $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/employees', $this->payload(['daily_rate' => 470.00]))
            ->assertUnprocessable();

        $msg = $response->json('errors.daily_rate.0');
        $this->assertStringContainsString('Region VII minimum wage', $msg);
    }

    public function test_store_assigns_next_employee_code(): void
    {
        $created = $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/employees', $this->payload())
            ->assertCreated();

        $this->assertStringMatchesFormat('ADC-%d', $created->json('data.employee_code'));
    }

    public function test_store_accepts_explicit_employee_code(): void
    {
        $created = $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/employees', $this->payload(['employee_code' => 'ADC-7777']))
            ->assertCreated();

        $this->assertSame('ADC-7777', $created->json('data.employee_code'));
    }

    public function test_duplicate_employee_code_is_rejected(): void
    {
        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/employees', $this->payload(['employee_code' => 'ADC-7778']))
            ->assertCreated();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/employees', $this->payload(['employee_code' => 'ADC-7778']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_code']);
    }

    public function test_index_filters_by_search_role_site_and_status(): void
    {
        $siteA = $this->site('Site A — Filter');
        $siteB = $this->site('Site B — Filter');

        $probieOnA = $this->loginUser('worker', [
            'employee_code' => 'ADC-4101',
            'first_name' => 'Efren', 'last_name' => 'Cabudbud',
            'site_id' => $siteA->site_id,
            'employment_status' => 'probationary',
        ]);
        $regularOnB = $this->loginUser('worker', [
            'employee_code' => 'ADC-4102',
            'first_name' => 'Rayla', 'last_name' => 'Lanaza',
            'site_id' => $siteB->site_id,
            'employment_status' => 'regular',
        ]);
        $foreman = $this->loginUser('foreman', ['employee_code' => 'ADC-4103', 'employment_status' => 'project_based']);

        $as = fn () => $this->actingAs($this->hr, 'sanctum');

        // search by last name
        $as()->getJson('/api/employees?search=Cabudbud')->assertOk()->assertJsonCount(1, 'data');
        // search by employee code
        $as()->getJson('/api/employees?search=ADC-4102')->assertOk()->assertJsonPath('data.0.last_name', 'Lanaza');
        // role filter (slug)
        $as()->getJson('/api/employees?role=foreman')->assertOk()->assertJsonCount(1, 'data');
        // site filter
        $as()->getJson('/api/employees?site_id='.$siteB->site_id)->assertOk()->assertJsonCount(1, 'data');
        // employment status filter
        $as()->getJson('/api/employees?employment_status=regular')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_is_paginated(): void
    {
        Employee::factory()->count(30)->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site()->site_id,
        ]);

        $response = $this->actingAs($this->hr, 'sanctum')
            ->getJson('/api/employees?per_page=10')
            ->assertOk();

        $this->assertSame(10, count($response->json('data')));
        $this->assertSame(10, $response->json('meta.per_page'));
        $this->assertSame(31, $response->json('meta.total'));
    }

    public function test_update_round_trips_certification_json(): void
    {
        $subject = $this->loginUser('worker', ['employee_code' => 'ADC-4201']);

        $certs = [
            ['name' => 'Scaffolding Erector II', 'issuer' => 'TESDA', 'certificate_no' => 'TESDA-SE2-114882', 'issued_at' => '2024-08-28', 'expires_at' => '2026-08-28'],
        ];

        $this->actingAs($this->hr, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['certification' => $certs])
            ->assertOk()
            ->assertJsonCount(1, 'data.certification')
            ->assertJsonPath('data.certification.0.issuer', 'TESDA');

        $this->assertSame($certs, Employee::find($subject->employee_id)->certification);
    }

    public function test_update_supports_emergency_contact(): void
    {
        $subject = $this->loginUser('worker', ['employee_code' => 'ADC-4202']);

        $contact = ['name' => 'Editha M.', 'mobile' => '+63 928 771 3082', 'alternate' => 'Rey A. — +63 995 220 4417', 'hospital' => 'Chong Hua Mandaue'];

        $this->actingAs($this->hr, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['emergency_contact' => $contact])
            ->assertOk()
            ->assertJsonPath('data.emergency_contact.name', 'Editha M.');
    }

    public function test_update_password_is_hashed_and_does_not_clear_when_omitted(): void
    {
        $subject = $this->loginUser('worker', ['employee_code' => 'ADC-4203']);

        $this->actingAs($this->hr, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['password' => 'NewSecret123!'])
            ->assertOk();

        $fresh = $subject->fresh();
        $this->assertNotSame('NewSecret123!', $fresh->password);
        $this->assertTrue(Hash::check('NewSecret123!', $fresh->password));

        // A subsequent update without password keeps the existing hash.
        $this->actingAs($this->hr, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['first_name' => 'Still'])
            ->assertOk();

        $fresh = $subject->fresh();
        $this->assertTrue(Hash::check('NewSecret123!', $fresh->password));
    }

    public function test_separation_keeps_record_searchable(): void
    {
        $subject = $this->loginUser('worker', ['employee_code' => 'ADC-4204']);

        $this->actingAs($this->hr, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['employment_status' => 'separated'])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'separated');

        $this->actingAs($this->hr, 'sanctum')
            ->getJson('/api/employees?search=ADC-4204&employment_status=separated')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_loads_role_site_and_assignments(): void
    {
        $subject = $this->loginUser('worker', ['employee_code' => 'ADC-4205']);

        $this->actingAs($this->hr, 'sanctum')
            ->getJson("/api/employees/{$subject->employee_id}")
            ->assertOk()
            ->assertJsonPath('data.employee_code', 'ADC-4205')
            ->assertJsonPath('data.role.slug', 'worker')
            ->assertJsonPath('data.site.site_name', $this->site()->site_name);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site()->site_id,
            'first_name' => 'New',
            'last_name' => 'Employee',
            'middle_name' => null,
            'date_of_birth' => '1995-01-01',
            'mobile' => '+63 917 555 0101',
            'date_hired' => '2026-09-01',
            'employment_status' => 'probationary',
            'daily_rate' => 820.00,
            'cost_centre' => 'CO-04',
        ], $overrides);
    }
}
