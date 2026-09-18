<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\DeviceKey;
use App\Models\Employee;
use Database\Factories\DeviceKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * STD TC-05 — Absent Foreman Re-assignment (Phase 7, UC-06).
 *
 * Crew X is led by F1. At 08:30 on Saturday 12 Sep 2026 (site time) a Site
 * Engineer hands it to F2 for the day. Roll call is taken offline, so what
 * matters is who led the crew when each tap was CAPTURED — not when it synced.
 */
class ActingForemanTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    private Employee $engineer;

    private Employee $regular;

    private Employee $acting;

    private DeviceKey $regularDevice;

    private string $regularHmac;

    private DeviceKey $actingDevice;

    private string $actingHmac;

    protected function setUp(): void
    {
        parent::setUp();

        // The crew exists from early morning, before anyone is absent.
        $this->travelTo($this->manila('06:00'));

        $this->setUpSignedDevice();
        $this->regular = $this->foreman;
        $this->regularDevice = $this->device;
        $this->regularHmac = $this->hmacKeyBase64;

        $this->acting = $this->loginUser('foreman');
        $this->actingHmac = base64_encode(random_bytes(32));
        $this->actingDevice = DeviceKey::factory()->create([
            'employee_id' => $this->acting->employee_id,
            'device_id' => 'dev-sync-0002',
            'public_key' => DeviceKeyFactory::TEST_PUBLIC_KEY_PEM,
            'hmac_key' => $this->actingHmac,
        ]);

        $this->engineer = $this->loginUser('engineer');

        $this->travelTo($this->manila('08:30'));
    }

    public function test_the_engineer_hands_the_crew_over_in_one_action(): void
    {
        $this->assign()
            ->assertOk()
            ->assertJsonPath('data.foreman.employee_id', $this->acting->employee_id)
            ->assertJsonPath('data.acting.regular_foreman.employee_id', $this->regular->employee_id);

        $crew = Crew::query()->find($this->crewId);
        $this->assertSame($this->acting->employee_id, $crew->foreman_id);
        $this->assertSame($this->regular->employee_id, $crew->regular_foreman_id);
        // "Today" ends at midnight on site, not at midnight UTC.
        $this->assertTrue($crew->acting_until->eq($this->manila('23:59:59')));

        $audit = AuditLog::query()->where('action_type', AuditLog::ACTING_FOREMAN_ASSIGNED)->sole();
        $this->assertSame($this->engineer->employee_id, $audit->actor_id);
        $this->assertSame($this->crewId, $audit->crew_id);
        $this->assertTrue($audit->timestamp->eq($this->manila('08:30')));
    }

    /** "Prior assignment history is preserved through tbl_crew_assignment.status rather than being deleted." */
    public function test_the_regular_foremans_period_is_ended_not_deleted(): void
    {
        $this->assign()->assertOk();

        $history = CrewAssignment::query()
            ->where('crew_id', $this->crewId)
            ->whereIn('assignment_type', CrewAssignment::LEADERSHIP_TYPES)
            ->orderBy('assignment_id')
            ->get();

        $this->assertCount(2, $history);

        [$regular, $acting] = $history;
        $this->assertSame($this->regular->employee_id, $regular->employee_id);
        $this->assertSame(CrewAssignment::STATUS_ENDED, $regular->status);
        $this->assertTrue($regular->ended_at->eq($this->manila('08:30')));

        $this->assertSame($this->acting->employee_id, $acting->employee_id);
        $this->assertSame(CrewAssignment::TYPE_ACTING_FOREMAN, $acting->assignment_type);
        $this->assertSame('active', $acting->status);
        $this->assertNull($acting->ended_at);
    }

    public function test_the_acting_foreman_gets_the_roster_and_their_roll_call_is_accepted(): void
    {
        $this->assign()->assertOk();

        $this->actingAs($this->acting, 'sanctum')->getJson('/api/me/crew')
            ->assertOk()
            ->assertJsonPath('crew.crew_id', $this->crewId)
            ->assertJsonPath('crew.acting.regular_foreman.employee_id', $this->regular->employee_id);

        $this->asActingForeman();
        $this->sync($this->tapAt('08:45'))
            ->assertOk()
            ->assertJsonPath('accepted', 1);
    }

    public function test_the_regular_foreman_loses_the_crew_while_covered(): void
    {
        $this->assign()->assertOk();

        $this->actingAs($this->regular, 'sanctum')->getJson('/api/me/crew')
            ->assertOk()
            ->assertJsonPath('crew', null);

        $this->sync($this->tapAt('08:45'))
            ->assertJsonPath('refused', 1)
            ->assertJsonPath('results.0.reason', 'not_crew_foreman');
    }

    /** Offline: tapped at 07:10 while still the foreman, synced after the handover. */
    public function test_roll_call_taken_before_the_handover_still_syncs_after_it(): void
    {
        $this->assign()->assertOk();

        $this->sync($this->tapAt('07:10'))
            ->assertOk()
            ->assertJsonPath('accepted', 1);
    }

    public function test_the_cover_ends_on_its_own_at_the_end_of_the_day(): void
    {
        $this->assign()->assertOk();

        $this->travelTo($this->manila('06:00', '2026-09-13'));

        // Any request ends expired covers before reading leadership.
        $this->actingAs($this->regular, 'sanctum')->getJson('/api/me/crew')
            ->assertOk()
            ->assertJsonPath('crew.crew_id', $this->crewId)
            ->assertJsonPath('crew.acting', null);

        $crew = Crew::query()->find($this->crewId);
        $this->assertSame($this->regular->employee_id, $crew->foreman_id);
        $this->assertNull($crew->regular_foreman_id);
        $this->assertNull($crew->acting_until);

        $ended = AuditLog::query()->where('action_type', AuditLog::ACTING_FOREMAN_ENDED)->sole();
        $this->assertSame($this->engineer->employee_id, $ended->actor_id);
        $this->assertStringContainsString('expired', $ended->description);
        // Dated at the expiry, not at whenever someone next looked.
        $this->assertTrue($ended->timestamp->eq($this->manila('23:59:59')));

        $this->actingAs($this->acting, 'sanctum')->getJson('/api/me/crew')
            ->assertJsonPath('crew', null);
    }

    public function test_a_tap_by_the_acting_foreman_after_expiry_is_refused(): void
    {
        $this->assign()->assertOk();
        $this->travelTo($this->manila('06:00', '2026-09-13'));

        $this->asActingForeman();
        $this->sync($this->tapAt('00:30', '2026-09-13'))
            ->assertJsonPath('refused', 1);
    }

    public function test_the_scheduled_command_ends_expired_covers(): void
    {
        $this->assign()->assertOk();
        $this->travelTo($this->manila('00:05', '2026-09-13'));

        $this->artisan('crews:end-expired-covers')
            ->expectsOutputToContain('1 expired acting foreman cover(s) ended.')
            ->assertSuccessful();

        $this->assertSame($this->regular->employee_id, Crew::query()->find($this->crewId)->foreman_id);
    }

    public function test_this_week_runs_to_the_end_of_sunday(): void
    {
        $this->assign(duration: 'week')->assertOk();

        $this->assertTrue(
            Crew::query()->find($this->crewId)->acting_until->eq($this->manila('23:59:59', '2026-09-13'))
        );
    }

    public function test_the_engineer_can_end_the_cover_early(): void
    {
        $this->assign()->assertOk();

        $this->travelTo($this->manila('11:00'));

        $this->actingAs($this->engineer, 'sanctum')
            ->deleteJson("/api/crews/{$this->crewId}/acting-foreman")
            ->assertOk()
            ->assertJsonPath('data.foreman.employee_id', $this->regular->employee_id)
            ->assertJsonPath('data.acting', null);

        $ended = AuditLog::query()->where('action_type', AuditLog::ACTING_FOREMAN_ENDED)->sole();
        $this->assertSame($this->engineer->employee_id, $ended->actor_id);

        // The acting foreman's taps from before 11:00 remain theirs to sync.
        $this->asActingForeman();
        $this->sync($this->tapAt('10:30'))->assertJsonPath('accepted', 1);
    }

    public function test_only_a_free_site_foreman_can_cover(): void
    {
        $worker = $this->worker();
        $this->assign($worker)->assertUnprocessable();

        $busy = $this->loginUser('foreman');
        Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $busy->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);
        $this->assign($busy)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Leads'));

        $noLogin = Employee::factory()->create(['role_id' => $this->role('foreman')->role_id]);
        $this->assign($noLogin)->assertUnprocessable();

        $this->assign($this->regular)->assertUnprocessable();

        $this->assertSame($this->regular->employee_id, Crew::query()->find($this->crewId)->foreman_id);
    }

    public function test_a_crew_has_one_cover_at_a_time(): void
    {
        $this->assign()->assertOk();

        $this->assign($this->loginUser('foreman'))
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already has an acting foreman'));
    }

    public function test_only_a_deployed_crew_can_be_covered(): void
    {
        Crew::query()->whereKey($this->crewId)->update(['status' => 'draft']);

        $this->assign()->assertUnprocessable();
    }

    public function test_only_site_engineers_hand_crews_over(): void
    {
        foreach (['hr', 'executive'] as $role) {
            $this->assign(by: $this->loginUser($role))->assertForbidden();
        }

        foreach (['foreman', 'admin'] as $role) {
            $this->assign(by: $this->loginUser($role))->assertForbidden();
        }

        $this->assertSame($this->regular->employee_id, Crew::query()->find($this->crewId)->foreman_id);
    }

    public function test_the_picker_lists_every_site_foreman_with_why_not(): void
    {
        $busy = $this->loginUser('foreman', ['first_name' => 'Busy']);
        Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $busy->employee_id,
            'crew_name' => 'Rebar crew D',
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);

        $candidates = collect(
            $this->actingAs($this->engineer, 'sanctum')
                ->getJson("/api/crews/{$this->crewId}/acting-candidates")
                ->assertOk()
                ->json('candidates')
        )->keyBy('employee_id');

        $this->assertFalse($candidates->has($this->regular->employee_id));
        $this->assertTrue($candidates[$this->acting->employee_id]['available']);
        $this->assertFalse($candidates[$busy->employee_id]['available']);
        $this->assertStringContainsString('Rebar crew D', $candidates[$busy->employee_id]['unavailable_reason']);
    }

    public function test_a_new_regular_foreman_cannot_be_designated_mid_cover(): void
    {
        $this->assign()->assertOk();

        $this->actingAs($this->engineer, 'sanctum')
            ->putJson("/api/crews/{$this->crewId}/foreman", ['foreman_id' => $this->loginUser('foreman')->employee_id])
            ->assertUnprocessable();
    }

    public function test_designating_a_foreman_keeps_the_previous_one_in_history(): void
    {
        $next = $this->loginUser('foreman');

        $this->actingAs($this->engineer, 'sanctum')
            ->putJson("/api/crews/{$this->crewId}/foreman", ['foreman_id' => $next->employee_id])
            ->assertOk();

        $this->assertSame(
            [[$this->regular->employee_id, CrewAssignment::STATUS_ENDED], [$next->employee_id, 'active']],
            CrewAssignment::query()
                ->where('crew_id', $this->crewId)
                ->where('assignment_type', CrewAssignment::TYPE_FOREMAN)
                ->orderBy('assignment_id')
                ->get()
                ->map(fn (CrewAssignment $a) => [$a->employee_id, $a->status])
                ->all(),
        );

        // Leadership rows are not roster members.
        $this->actingAs($next, 'sanctum')->getJson('/api/me/crew')
            ->assertJsonCount(0, 'crew.members');
    }

    private function assign(?Employee $foreman = null, string $duration = 'today', ?Employee $by = null)
    {
        return $this->actingAs($by ?? $this->engineer, 'sanctum')
            ->postJson("/api/crews/{$this->crewId}/acting-foreman", [
                'foreman_id' => ($foreman ?? $this->acting)->employee_id,
                'duration' => $duration,
            ]);
    }

    /** Sign and send as the acting foreman's own phone from here on. */
    private function asActingForeman(): void
    {
        $this->foreman = $this->acting;
        $this->device = $this->actingDevice;
        $this->hmacKeyBase64 = $this->actingHmac;
    }

    /** One ordinary Present tap for a fresh worker, captured at a site time. */
    private function tapAt(string $time, string $date = '2026-09-12'): array
    {
        $at = $this->manila($time, $date)->getTimestampMs();

        return $this->buildBatch([$this->worker()->employee_id], null, [[
            'date' => $date,
            'time_in' => $at,
            'captured_at' => $at,
        ]]);
    }

    private function manila(string $time, string $date = '2026-09-12'): Carbon
    {
        return Carbon::parse("{$date} {$time}", 'Asia/Manila');
    }
}
