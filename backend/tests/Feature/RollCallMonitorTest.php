<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\DeviceKey;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roll Call monitor (C4, UC-04/FR-03) — a read-only "today" view over what
 * the phones sent. Capture stays mobile-only; this endpoint only reports
 * per deployed crew what arrived, what is missing, and what waits for
 * review. Membership is always today's roster, even for a past date.
 */
class RollCallMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function crewWithMembers(Employee $foreman, int $members, string $site): Crew
    {
        $crew = Crew::factory()->create([
            'site_id' => $this->site($site)->site_id,
            'foreman_id' => $foreman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ]);

        for ($i = 0; $i < $members; $i++) {
            $worker = Employee::factory()->create([
                'role_id' => $this->role('worker')->role_id,
                'site_id' => $crew->site_id,
            ]);
            CrewAssignment::factory()->create([
                'crew_id' => $crew->crew_id,
                'employee_id' => $worker->employee_id,
                'assignment_type' => CrewAssignment::TYPE_MEMBER,
                'status' => 'active',
            ]);
        }

        return $crew;
    }

    private function record(int $employeeId, int $crewId, string $date, string $status, array $overrides = []): Attendance
    {
        return Attendance::create(array_merge([
            'employee_id' => $employeeId,
            'crew_id' => $crewId,
            'date' => $date,
            'status' => $status,
            'sync_status' => 'synced',
        ], $overrides));
    }

    private function manilaToday(): string
    {
        return Carbon::now(config('attendance.timezone', 'Asia/Manila'))->toDateString();
    }

    public function test_engineer_sees_their_own_sites_crews_with_counts(): void
    {
        $siteA = $this->site('Site A');
        $engineer = $this->loginUser('engineer', ['site_id' => $siteA->site_id]);
        $foreman = $this->loginUser('foreman', ['site_id' => $siteA->site_id]);
        $crew = $this->crewWithMembers($foreman, 3, 'Site A');
        $this->crewWithMembers($this->loginUser('foreman'), 2, 'Site B');

        $date = $this->manilaToday();
        $members = CrewAssignment::where('crew_id', $crew->crew_id)->pluck('employee_id');
        $this->record($members[0], $crew->crew_id, $date, 'present');
        $this->record($members[1], $crew->crew_id, $date, 'late');

        $response = $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/rollcall/today')
            ->assertOk();

        $this->assertSame($date, $response->json('date'));
        $this->assertSame($date, $response->json('roster_as_of'));
        $this->assertNull($response->json('roster_note'));

        $cards = $response->json('data');
        $this->assertSame(1, count($cards));
        $this->assertSame($crew->crew_id, $cards[0]['crew_id']);
        $this->assertSame(3, $cards[0]['crew_size']);
        $this->assertSame(['present' => 1, 'late' => 1], $cards[0]['received']);
        $this->assertSame(1, $cards[0]['missing']);
        $this->assertFalse($cards[0]['nothing_received']);
    }

    public function test_engineer_without_a_home_site_gets_403(): void
    {
        $engineer = $this->loginUser('engineer', ['site_id' => null]);

        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/rollcall/today')
            ->assertForbidden();
    }

    public function test_foreman_sees_only_the_crews_they_lead_now(): void
    {
        $foreman = $this->loginUser('foreman');
        $otherForeman = $this->loginUser('foreman');
        $mine = $this->crewWithMembers($foreman, 2, 'Site A');
        $theirs = $this->crewWithMembers($otherForeman, 2, 'Site A');

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/rollcall/today')
            ->assertOk();

        $ids = array_column($response->json('data'), 'crew_id');
        $this->assertSame([$mine->crew_id], $ids);
        $this->assertNotContains($theirs->crew_id, $ids);
    }

    public function test_a_crew_with_nothing_received_is_flagged(): void
    {
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewWithMembers($foreman, 2, 'Site A');

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/rollcall/today')
            ->assertOk();

        $card = $response->json('data.0');
        $this->assertSame($crew->crew_id, $card['crew_id']);
        $this->assertTrue($card['nothing_received']);
        $this->assertSame([], $card['received']);
        $this->assertSame(2, $card['missing']);
    }

    public function test_pending_reviews_and_last_sync_are_reported(): void
    {
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewWithMembers($foreman, 2, 'Site A');
        $date = $this->manilaToday();

        $members = CrewAssignment::where('crew_id', $crew->crew_id)->pluck('employee_id');
        $pending = AuditLog::create([
            'actor_id' => $foreman->employee_id,
            'action_type' => AuditLog::LATE_OVERRIDE,
            'description' => 'Test override',
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_PENDING,
        ]);
        $this->record($members[0], $crew->crew_id, $date, 'present', ['override_audit_id' => $pending->audit_id]);
        $this->record($members[1], $crew->crew_id, $date, 'present');

        DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-sync-0001',
            'last_synced_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/rollcall/today')
            ->assertOk();

        $card = $response->json('data.0');
        $this->assertSame(1, $card['pending_reviews']);
        $this->assertNotNull($card['foreman_last_synced_at']);
    }

    public function test_date_validation(): void
    {
        $engineer = $this->loginUser('engineer', ['site_id' => $this->site('Site A')->site_id]);
        $tz = config('attendance.timezone', 'Asia/Manila');
        $today = Carbon::now($tz);

        // Past dates carry the roster note; membership stays today's roster.
        $past = $today->copy()->subDays(3)->toDateString();
        $response = $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/rollcall/today?date='.$past)
            ->assertOk();
        $this->assertSame($past, $response->json('date'));
        $this->assertSame($today->toDateString(), $response->json('roster_as_of'));
        $this->assertNotNull($response->json('roster_note'));

        // The back edge of the window still works.
        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/rollcall/today?date='.$today->copy()->subDays(62)->toDateString())
            ->assertOk();

        // Future, too far back, and garbage are all 422.
        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/rollcall/today?date='.$today->copy()->addDay()->toDateString())
            ->assertUnprocessable();
        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/rollcall/today?date='.$today->copy()->subDays(63)->toDateString())
            ->assertUnprocessable();
        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/rollcall/today?date=10/09/2026')
            ->assertUnprocessable();
    }

    public function test_other_roles_are_denied(): void
    {
        foreach (['hr', 'admin', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/rollcall/today')
                ->assertForbidden();
        }
    }

    public function test_response_never_carries_rates_or_government_ids(): void
    {
        $foreman = $this->loginUser('foreman');
        $this->crewWithMembers($foreman, 1, 'Site A');

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/rollcall/today')
            ->assertOk();

        foreach (['daily_rate', 'tin', 'sss', 'philhealth', 'pag_ibig', 'date_of_birth', 'address', 'blood_type'] as $field) {
            $response->assertJsonMissingPath("data.0.{$field}");
        }
    }
}
