<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Sites (C2, UC-03). The admin's registry plus the read-only
 * overview: headcount, deployed crews and engineers per site. Sites close,
 * never delete; closing is refused while deployed crews remain. The
 * reference dropdown list needs no cache code — a Site save already retires
 * it — but a test pins that a new site shows up there at once.
 */
class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_close_and_reopen_a_site(): void
    {
        $admin = $this->loginUser('admin');

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/sites', ['site_name' => 'Site 12 — Liloan Estate', 'location' => 'Liloan, Cebu'])
            ->assertCreated()
            ->assertJsonPath('data.site_name', 'Site 12 — Liloan Estate')
            ->assertJsonPath('data.status', 'active');

        $siteId = $created->json('data.site_id');
        $this->assertDatabaseHas('audit_logs', ['action_type' => AuditLog::SITE_CREATED]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/sites/{$siteId}", ['site_name' => 'Site 12 — Liloan Estate', 'location' => 'Liloan, Cebu (north gate)'])
            ->assertOk()
            ->assertJsonPath('data.location', 'Liloan, Cebu (north gate)');
        $this->assertDatabaseHas('audit_logs', ['action_type' => AuditLog::SITE_UPDATED]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/sites/{$siteId}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseHas('audit_logs', ['action_type' => AuditLog::SITE_CLOSED]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/sites/{$siteId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action_type' => AuditLog::SITE_REOPENED]);
    }

    public function test_hr_and_engineer_cannot_change_anything(): void
    {
        $site = $this->site('Site A');

        foreach (['hr', 'engineer'] as $slug) {
            $user = $this->loginUser($slug, ['site_id' => $site->site_id]);

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/sites', ['site_name' => 'Site X', 'location' => 'Nowhere'])
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->putJson("/api/sites/{$site->site_id}", ['site_name' => 'Site A', 'location' => 'Moved'])
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/sites/{$site->site_id}/close")
                ->assertForbidden();
        }
    }

    public function test_closing_is_refused_while_crews_are_deployed(): void
    {
        $admin = $this->loginUser('admin');
        $site = $this->site('Site A');
        Crew::factory()->create([
            'site_id' => $site->site_id,
            'foreman_id' => $this->loginUser('foreman')->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/sites/{$site->site_id}/close")
            ->assertUnprocessable();

        $this->assertSame('active', $site->fresh()->status);
    }

    public function test_a_site_with_only_draft_crews_can_close(): void
    {
        $admin = $this->loginUser('admin');
        $site = $this->site('Site A');
        Crew::factory()->create([
            'site_id' => $site->site_id,
            'foreman_id' => $this->loginUser('foreman')->employee_id,
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/sites/{$site->site_id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_no_crew_can_be_created_at_a_closed_site(): void
    {
        $engineer = $this->loginUser('engineer');
        $site = $this->site('Site A');
        $site->update(['status' => Site::STATUS_CLOSED]);

        $this->actingAs($engineer, 'sanctum')
            ->postJson('/api/crews', ['site_id' => $site->site_id, 'crew_name' => 'Crew Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');
    }

    public function test_a_new_site_appears_in_the_reference_list_at_once(): void
    {
        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/sites', ['site_name' => 'Site 12 — Liloan Estate', 'location' => 'Liloan, Cebu'])
            ->assertCreated();

        // The dropdown list is cached; the save must have retired the entry.
        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/sites')->assertOk();
        $this->assertContains('Site 12 — Liloan Estate', array_column($list->json('sites'), 'site_name'));
    }

    public function test_overview_roles_and_engineer_scope(): void
    {
        $siteA = $this->site('Site A');
        $siteB = $this->site('Site B');
        $engineer = $this->loginUser('engineer', ['site_id' => $siteA->site_id]);
        $foreman = $this->loginUser('foreman', ['site_id' => $siteA->site_id]);
        Crew::factory()->create([
            'site_id' => $siteA->site_id,
            'foreman_id' => $foreman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);

        // HR and executive see every site with headcount, crews and engineers.
        foreach (['hr', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/sites/overview')
                ->assertOk();

            $this->assertGreaterThanOrEqual(2, count($response->json('data')));
            $rowA = collect($response->json('data'))->firstWhere('site_id', $siteA->site_id);
            $this->assertGreaterThanOrEqual(1, $rowA['headcount']);
            $this->assertSame(1, count($rowA['deployed_crews']));
            $this->assertSame($foreman->full_name, $rowA['deployed_crews'][0]['foreman']);
        }

        // The engineer sees their own site only.
        $response = $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/sites/overview')
            ->assertOk();

        $this->assertSame([$siteA->site_id], array_column($response->json('data'), 'site_id'));

        // No home site is a 403, never the company-wide view.
        $homeless = $this->loginUser('engineer', ['site_id' => null]);
        $this->actingAs($homeless, 'sanctum')
            ->getJson('/api/sites/overview')
            ->assertForbidden();

        // Admin sees the overview too.
        $this->actingAs($this->loginUser('admin'), 'sanctum')
            ->getJson('/api/sites/overview')
            ->assertOk();

        // A foreman has no site overview at all.
        $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/sites/overview')
            ->assertForbidden();
    }

    public function test_response_never_carries_rates_or_government_ids(): void
    {
        $admin = $this->loginUser('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/sites/overview')
            ->assertOk();

        foreach (['daily_rate', 'tin', 'sss', 'philhealth', 'pag_ibig', 'date_of_birth', 'address', 'blood_type'] as $field) {
            $response->assertJsonMissingPath("data.0.{$field}");
        }
    }
}
