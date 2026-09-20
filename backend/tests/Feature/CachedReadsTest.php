<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Site;
use App\Support\ResilientCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cached read endpoints (Redis Cluster add-on): the expensive ones are
 * answered from cache, and a write retires what it changed, so nobody is shown
 * a figure that a save already contradicted.
 *
 * The cache under test here is the array store (phpunit.xml forces it); what
 * differs in the containers is only where the entries live.
 */
class CachedReadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ResilientCache::reset();
    }

    public function test_the_dashboard_is_answered_from_cache_within_its_window(): void
    {
        $executive = $this->loginUser('executive');

        $first = $this->actingAs($executive, 'sanctum')->getJson('/api/reports/overview')->assertOk();

        $this->travel(30)->seconds();

        $second = $this->actingAs($executive, 'sanctum')->getJson('/api/reports/overview')->assertOk();

        // generated_at is when the figures were computed: unchanged means the
        // second request did not recompute them.
        $this->assertSame($first->json('data.generated_at'), $second->json('data.generated_at'));
    }

    public function test_hiring_someone_retires_the_dashboard_and_the_registry(): void
    {
        $executive = $this->loginUser('executive');
        $hr = $this->loginUser('hr');
        $site = $this->site();

        $before = $this->actingAs($executive, 'sanctum')->getJson('/api/reports/overview')->json('data.kpis.headcount');
        $this->actingAs($hr, 'sanctum')->getJson('/api/employees')->assertOk();

        Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $site->site_id, 'last_name' => 'Nuevo']);

        $this->assertSame(
            $before + 1,
            $this->actingAs($executive, 'sanctum')->getJson('/api/reports/overview')->json('data.kpis.headcount'),
        );
        $this->assertStringContainsString(
            'Nuevo',
            (string) $this->actingAs($hr, 'sanctum')->getJson('/api/employees')->getContent(),
        );
    }

    public function test_an_edit_shows_in_the_registry_at_once(): void
    {
        $hr = $this->loginUser('hr');
        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'last_name' => 'Before']);

        $this->actingAs($hr, 'sanctum')->getJson('/api/employees')->assertOk()->assertSee('Before');

        $worker->update(['last_name' => 'After']);

        $this->actingAs($hr, 'sanctum')->getJson('/api/employees')->assertOk()->assertSee('After')->assertDontSee('Before');
    }

    public function test_a_new_site_shows_in_the_reference_list_at_once(): void
    {
        $hr = $this->loginUser('hr');
        $this->site();

        // Asserted on the decoded JSON: the site name carries an em dash, which
        // the response escapes as —, so a raw string search would miss it.
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/sites')
            ->assertOk()
            ->assertJsonMissing(['site_name' => 'Site 99 — Added']);

        Site::factory()->create(['site_name' => 'Site 99 — Added']);

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/sites')
            ->assertOk()
            ->assertJsonFragment(['site_name' => 'Site 99 — Added']);
    }

    /** With Redis unreachable the endpoint still answers, from the database. */
    public function test_the_dashboard_still_answers_when_the_cache_is_down(): void
    {
        $executive = $this->loginUser('executive');

        $this->mock(ResilientCache::class, function ($mock) {
            $mock->shouldReceive('remember')->andReturnUsing(fn ($namespace, $key, $ttl, $compute) => $compute());
        });

        $this->actingAs($executive, 'sanctum')
            ->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.score.band', 'good');
    }
}
