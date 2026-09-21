<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compliance & Docs → Certifications (C1, UC-02/UC-09). Reads the
 * certifications stored on employee records — no writes, no new table. The
 * statuses come from CertificationStatus::each(), the same helper the
 * scorecard counts from, so the two screens cannot disagree.
 */
class ComplianceCertificationsTest extends TestCase
{
    use RefreshDatabase;

    private const AS_OF = '2025-03-17';

    private function worker(string $site, ?array $certification): Employee
    {
        return Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site($site)->site_id,
            'certification' => $certification,
        ]);
    }

    private function cert(string $expiresAt): array
    {
        return ['name' => 'Scaffolding', 'issuer' => 'TESDA', 'certificate_no' => 'N-1', 'issued_at' => '2024-01-01', 'expires_at' => $expiresAt];
    }

    public function test_hr_and_executive_see_everything(): void
    {
        $this->worker('Site A', [$this->cert('2020-01-01')]);
        $this->worker('Site B', [$this->cert('2020-01-01')]);

        foreach (['hr', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/compliance/certifications?as_of='.self::AS_OF)
                ->assertOk();

            $this->assertSame(2, count($response->json('data')));
            $this->assertSame(2, $response->json('summary.expired'));
        }
    }

    public function test_engineer_sees_only_their_home_site_and_their_site_id_is_ignored(): void
    {
        $siteA = $this->site('Site A');
        $siteB = $this->site('Site B');
        $this->worker('Site A', [$this->cert('2020-01-01')]);
        $this->worker('Site B', [$this->cert('2020-01-01')]);

        $engineer = $this->loginUser('engineer', ['site_id' => $siteA->site_id]);

        $response = $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF."&site_id={$siteB->site_id}")
            ->assertOk();

        $rows = $response->json('data');
        $this->assertSame(1, count($rows));
        $this->assertSame('Site A', $rows[0]['site']);
    }

    public function test_engineer_without_a_home_site_gets_403(): void
    {
        $engineer = $this->loginUser('engineer', ['site_id' => null]);

        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF)
            ->assertForbidden();
    }

    public function test_foreman_sees_only_their_deployed_crew_members(): void
    {
        $foreman = $this->loginUser('foreman');
        $otherForeman = $this->loginUser('foreman');

        $crew = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $foreman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);
        $member = $this->worker('Site A', [$this->cert('2020-01-01')]);
        CrewAssignment::factory()->create([
            'crew_id' => $crew->crew_id,
            'employee_id' => $member->employee_id,
            'assignment_type' => CrewAssignment::TYPE_MEMBER,
            'status' => 'active',
        ]);

        $outsider = $this->worker('Site A', [$this->cert('2020-01-01')]);
        $otherCrew = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $otherForeman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);
        CrewAssignment::factory()->create([
            'crew_id' => $otherCrew->crew_id,
            'employee_id' => $outsider->employee_id,
            'assignment_type' => CrewAssignment::TYPE_MEMBER,
            'status' => 'active',
        ]);

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF)
            ->assertOk();

        $rows = $response->json('data');
        $this->assertSame(1, count($rows));
        $this->assertSame($member->employee_code, $rows[0]['employee_code']);
    }

    public function test_status_boundaries_and_no_expiry(): void
    {
        $hr = $this->loginUser('hr');

        $this->worker('Site A', [
            $this->cert('2025-03-16'), // expired yesterday
            $this->cert('2025-03-31'), // due in 14 days: expiring soon
            $this->cert('2025-04-01'), // due in 15 days: valid
            ['name' => 'First Aid', 'issuer' => 'Red Cross', 'certificate_no' => 'N-2', 'issued_at' => '2024-01-01', 'expires_at' => null],
        ]);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF)
            ->assertOk();

        $this->assertSame(['expired', 'expiring_soon', 'valid', 'no_expiry'], array_column($response->json('data'), 'status'));
        $this->assertSame(1, $response->json('summary.expired'));
        $this->assertSame(1, $response->json('summary.expiring_soon'));
        $this->assertSame(1, $response->json('summary.valid'));
        $this->assertSame(1, $response->json('summary.no_expiry'));

        $filtered = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF.'&status=expired')
            ->assertOk();

        $this->assertSame(1, count($filtered->json('data')));
        // The filter narrows rows only; the summary still describes the scope.
        $this->assertSame(1, $filtered->json('summary.valid'));
    }

    public function test_workers_without_certificates_are_counted(): void
    {
        $hr = $this->loginUser('hr');
        $this->worker('Site A', null);
        $this->worker('Site A', []);
        $this->worker('Site A', [$this->cert('2020-01-01')]);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF)
            ->assertOk();

        // The signed-in HR viewer is in scope too (non-separated, no certs).
        $this->assertSame(3, $response->json('summary.workers_without_certificates'));
    }

    public function test_expired_count_matches_the_scorecard_for_the_same_date(): void
    {
        $hr = $this->loginUser('hr');
        $site = $this->site('Site A');
        $this->worker('Site A', [$this->cert('2020-01-01'), $this->cert('2020-06-01')]);
        $this->worker('Site A', [$this->cert('2030-01-01')]);

        $mine = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF."&site_id={$site->site_id}")
            ->assertOk();

        // A fresh window the suite never uses, so the reports cache cannot
        // serve another test's numbers back.
        $score = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/reports/overview?from='.self::AS_OF.'&to='.self::AS_OF)
            ->assertOk();

        $siteRow = collect($score->json('data.sites'))->firstWhere('site_id', $site->site_id);

        $this->assertSame(2, $mine->json('summary.expired'));
        $this->assertSame($mine->json('summary.expired'), $siteRow['certifications_expired']);
    }

    public function test_response_never_carries_rates_or_government_ids(): void
    {
        $hr = $this->loginUser('hr');
        $this->worker('Site A', [$this->cert('2020-01-01')]);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/compliance/certifications?as_of='.self::AS_OF)
            ->assertOk();

        foreach (['daily_rate', 'tin', 'sss', 'philhealth', 'pag_ibig', 'date_of_birth', 'address', 'blood_type'] as $field) {
            $response->assertJsonMissingPath("data.0.{$field}");
        }
    }

    public function test_other_roles_are_denied(): void
    {
        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/compliance/certifications')
            ->assertForbidden();
    }
}
