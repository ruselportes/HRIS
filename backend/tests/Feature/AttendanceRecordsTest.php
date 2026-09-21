<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\DeviceKey;
use App\Models\Employee;
use App\Services\Attendance\CrewLeadership;
use Carbon\Carbon;
use Database\Factories\DeviceKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attendance records for the web portal's DTR (Phase 10 — the read side of
 * attendance; SDD Fig 20.0 / §4.4.4).
 *
 * Three scope rules are load-bearing and each gets a test:
 *  - HR/Admin/Executive see the whole filtered set (nav `attendance`).
 *  - An engineer's site is their home site; a site_id they send is ignored,
 *    and no home site is a 403, never an empty or a wider answer.
 *  - A foreman sees a record only if they led its crew at the instant the tap
 *    was captured — CrewLeadership::leads() per record — so a one-day cover
 *    sees exactly that one day, not the crew's whole history.
 * Filters are also tested to narrow, never to widen.
 */
class AttendanceRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-09-10';

    private function attendance(int $employeeId, int $crewId, string $date = self::DATE, string $status = 'present'): Attendance
    {
        return Attendance::query()->create([
            'employee_id' => $employeeId,
            'crew_id' => $crewId,
            'date' => $date,
            'status' => $status,
            'sync_status' => 'synced',
            'captured_at' => $date.' 07:00:00',
            'time_in' => $date.' 07:00:00',
        ]);
    }

    private function crewFor(Employee $foreman): Crew
    {
        return Crew::factory()->create([
            'site_id' => $foreman->site_id,
            'foreman_id' => $foreman->employee_id,
            'status' => 'deployed',
        ]);
    }

    public function test_hr_sees_records_with_summary_and_crew_options(): void
    {
        $hr = $this->loginUser('hr');
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewFor($foreman);
        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $hr->site_id]);
        $this->attendance($worker->employee_id, $crew->crew_id);
        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-11', 'late');

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('summary.present', 1);
        $response->assertJsonPath('summary.late', 1);
        $response->assertJsonPath('summary.total', 2);
        $response->assertJsonPath('crew_options.0.crew_id', $crew->crew_id);
        $response->assertJsonPath('data.0.employee.employee_code', $worker->employee_code);
        $response->assertJsonPath('data.0.site.site_name', $crew->site->site_name);
    }

    public function test_foreman_sees_their_own_crews_records_only(): void
    {
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewFor($foreman);
        $otherForeman = $this->loginUser('foreman');
        $otherCrew = $this->crewFor($otherForeman);
        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $foreman->site_id]);
        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-10');
        $this->attendance($worker->employee_id, $otherCrew->crew_id, '2026-09-11');

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.crew.crew_id', $crew->crew_id);
        $response->assertJsonPath('summary.total', 1);
    }

    public function test_a_one_day_cover_sees_exactly_that_one_day(): void
    {
        // The crew changed hands on 2026-09-05: the regular foreman led it
        // before, the cover foreman led it on 09-05 only, and the regular
        // foreman took it back after. crew.foreman_id is the *current* leader
        // (the regular), which is exactly why a record's visibility must be
        // decided per record from the leadership periods, not from the column.
        $regular = $this->loginUser('foreman');
        $cover = $this->loginUser('foreman');
        $crew = Crew::factory()->create([
            'site_id' => $regular->site_id,
            'foreman_id' => $regular->employee_id,
            'status' => 'deployed',
        ]);
        $service = new CrewLeadership;
        $service->handOver($crew, $regular->employee_id, CrewAssignment::TYPE_FOREMAN, Carbon::parse('2026-09-01 00:00:00'));
        $service->handOver($crew, $cover->employee_id, CrewAssignment::TYPE_ACTING_FOREMAN, Carbon::parse('2026-09-05 00:00:00'));
        $service->handOver($crew, $regular->employee_id, CrewAssignment::TYPE_FOREMAN, Carbon::parse('2026-09-06 00:00:00'));

        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $regular->site_id]);
        $covered = $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-05');
        $missed = $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-07');

        // Sanity: the cover foreman really did lead on the covered day only.
        $this->assertTrue($service->leads($cover->employee_id, $crew->crew_id, $covered->captured_at));
        $this->assertFalse($service->leads($cover->employee_id, $crew->crew_id, $missed->captured_at));

        $response = $this->actingAs($cover, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.attendance_id', $covered->attendance_id);
        $response->assertJsonPath('summary.total', 1);

        // The regular foreman, back after the cover, sees their own days again
        // — but not the day the cover led.
        $regularResponse = $this->actingAs($regular, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        $regularResponse->assertJsonCount(1, 'data');
        $regularResponse->assertJsonPath('data.0.attendance_id', $missed->attendance_id);
    }

    public function test_engineer_is_clamped_to_their_home_site(): void
    {
        $siteA = $this->site('Site A — Test');
        $siteB = $this->site('Site B — Test');
        $engineer = $this->loginUser('engineer', ['site_id' => $siteA->site_id]);
        $foreman = $this->loginUser('foreman', ['site_id' => $siteA->site_id]);
        $crewA = $this->crewFor($foreman);
        $foremanB = $this->loginUser('foreman', ['site_id' => $siteB->site_id]);
        $crewB = $this->crewFor($foremanB);
        $workerA = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $siteA->site_id]);
        $workerB = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $siteB->site_id]);
        $this->attendance($workerA->employee_id, $crewA->crew_id, '2026-09-10');
        $this->attendance($workerB->employee_id, $crewB->crew_id, '2026-09-10');

        // A site_id filter for site B is silently ignored — the clamp wins and
        // the response still only ever contains site A records.
        $response = $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30&site_id='.$siteB->site_id)
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.site.site_id', $siteA->site_id);
        $response->assertJsonPath('crew_options.0.crew_id', $crewA->crew_id);
    }

    public function test_engineer_without_a_home_site_gets_403(): void
    {
        $engineer = $this->loginUser('engineer', ['site_id' => null]);
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewFor($foreman);
        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $foreman->site_id]);
        $this->attendance($worker->employee_id, $crew->crew_id);

        $this->actingAs($engineer, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_filters_narrow_the_scope_and_never_widen_it(): void
    {
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewFor($foreman);
        $otherForeman = $this->loginUser('foreman');
        $otherCrew = $this->crewFor($otherForeman);
        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $foreman->site_id]);
        $this->attendance($worker->employee_id, $crew->crew_id, '2026-09-10');
        $this->attendance($worker->employee_id, $otherCrew->crew_id, '2026-09-11');

        // A foreman asking for a crew they do not lead gets an empty DTR, not
        // their crew's records relabelled and not the stranger's crew either.
        $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30&crew_id='.$otherCrew->crew_id)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.total', 0);
    }

    public function test_admin_and_executive_can_view_the_records(): void
    {
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewFor($foreman);
        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $foreman->site_id]);
        $this->attendance($worker->employee_id, $crew->crew_id);

        foreach (['admin', 'executive'] as $roleSlug) {
            $login = $this->loginUser($roleSlug);
            $this->actingAs($login, 'sanctum')
                ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    public function test_the_response_never_leaks_crypto_material_or_hmac_keys(): void
    {
        $hr = $this->loginUser('hr');
        $foreman = $this->loginUser('foreman');
        $crew = $this->crewFor($foreman);
        $worker = Employee::factory()->create(['role_id' => $this->role('worker')->role_id, 'site_id' => $hr->site_id]);
        $row = $this->attendance($worker->employee_id, $crew->crew_id);
        DeviceKey::factory()->create(['employee_id' => $foreman->employee_id, 'device_id' => 'dev-dtr-0001']);
        $row->cryptoSignature()->create([
            'attendance_id' => $row->attendance_id,
            'device_id' => 'dev-dtr-0001',
            'verified' => true,
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($hr, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('hmac', strtolower($body));
        $this->assertStringNotContainsString('signature', strtolower($body));
        $this->assertStringNotContainsString('hash', strtolower($body));
        $this->assertStringNotContainsString(DeviceKeyFactory::TEST_PUBLIC_KEY_PEM, $body);
    }

    public function test_invalid_windows_are_rejected_with_422(): void
    {
        $hr = $this->loginUser('hr');

        // to before from.
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422);

        // Window of 63 days — beyond the 62-day cap.
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-01-01&to=2026-03-05')
            ->assertStatus(422);

        // Missing the pair.
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-10')
            ->assertStatus(422);

        // Malformed date.
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/attendance/records?from=10/09/2026&to=2026-09-30')
            ->assertStatus(422);

        // Unknown site id.
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30&site_id=99999')
            ->assertStatus(422);
    }

    public function test_other_roles_are_denied(): void
    {
        $worker = Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'password' => 'password',
            'email' => 'w.'.uniqid().'@arcenasdev.ph',
        ]);

        $this->actingAs($worker, 'sanctum')
            ->getJson('/api/attendance/records?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }
}
