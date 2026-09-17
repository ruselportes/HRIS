<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Services\Attendance\TimeInPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * STD TC-04 — Late Foreman Override — and the HR review that decides whether
 * an overridden record is paid (Phase 7, UC-05).
 *
 * Driven through the real sync endpoint with correctly signed events, so the
 * override is exercised exactly as a phone produces it rather than by writing
 * rows directly.
 */
class LateOverrideTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    private const DATE = '2026-09-12';

    private const MINUTE = 60_000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'attendance.shift_start' => '07:00',
            'attendance.late_override_grace_minutes' => 15,
            'attendance.timezone' => 'Asia/Manila',
        ]);

        $this->setUpSignedDevice();
    }

    private function shiftStart(): int
    {
        return app(TimeInPolicy::class)->shiftStartMs(self::DATE);
    }

    /**
     * Shift-credit events for the given workers, tapped one minute apart
     * starting $minutesAfterShift after 07:00.
     */
    private function creditBatch(array $workerIds, int $minutesAfterShift = 140, ?string $prevHash = null, int $monotonicStart = 86_400_000): array
    {
        $overrides = [];

        foreach (array_values($workerIds) as $i => $id) {
            $overrides[$i] = [
                'date' => self::DATE,
                'status' => 'present',
                'time_in' => $this->shiftStart(),
                'captured_at' => $this->shiftStart() + ($minutesAfterShift + $i) * self::MINUTE,
                'override_type' => 'shift_credit',
                'monotonic_timestamp' => $monotonicStart + $i * self::MINUTE,
            ];
        }

        return $this->buildBatch($workerIds, $prevHash, $overrides);
    }

    private function hr()
    {
        return $this->loginUser('hr');
    }

    /** STD TC-04 as written: foreman opens roll call at 09:20, Workers A, B and C marked Present. */
    public function test_tc04_late_override_credits_shift_start_and_writes_one_audit_entry(): void
    {
        $workers = [$this->worker(), $this->worker(), $this->worker()];

        $this->sync($this->creditBatch(array_map(fn ($w) => $w->employee_id, $workers)))
            ->assertOk()
            ->assertJsonPath('accepted', 3);

        foreach ($workers as $i => $worker) {
            $row = Attendance::where('employee_id', $worker->employee_id)->firstOrFail();

            // "time_in is recorded as 07:00 rather than 09:20, and override_flag is set"
            $this->assertSame($this->shiftStart(), $row->time_in->getTimestampMs());
            $this->assertSame('shift_credit', $row->override_flag);
            // "...so that the credited time and the real time of entry are both recoverable"
            $this->assertSame($this->shiftStart() + (140 + $i) * self::MINUTE, $row->captured_at->getTimestampMs());
        }

        // "One tbl_audit_log entry ... with action_type FOREMAN_LATE_OVERRIDE,
        // the acting foreman as actor_id, and a timestamp reflecting the actual
        // time of the action (09:20)"
        $events = AuditLog::where('action_type', AuditLog::LATE_OVERRIDE)->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame($this->foreman->employee_id, $event->actor_id);
        $this->assertSame($this->crewId, $event->crew_id);
        $this->assertSame(self::DATE, $event->subject_date);
        $this->assertSame($this->shiftStart() + 140 * self::MINUTE, $event->timestamp->getTimestampMs());
        $this->assertSame(AuditLog::REVIEW_PENDING, $event->review_status);
        $this->assertSame(3, $event->overriddenAttendances()->count());
    }

    /** "The override is visible on the Late Override Audit screen." */
    public function test_tc04_the_override_is_visible_in_the_hr_review_queue(): void
    {
        $workers = [$this->worker(), $this->worker(), $this->worker()];
        $this->sync($this->creditBatch(array_map(fn ($w) => $w->employee_id, $workers)))->assertOk();

        $response = $this->actingAs($this->hr(), 'sanctum')
            ->getJson('/api/overrides')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.action_type', AuditLog::LATE_OVERRIDE);
        $response->assertJsonPath('data.0.record_count', 3);
        $response->assertJsonPath('data.0.review_status', 'pending');
        $response->assertJsonPath('data.0.actor.employee_id', $this->foreman->employee_id);
        // Credited 140 + 141 + 142 minutes earlier than the real taps.
        $response->assertJsonPath('data.0.hours_at_stake', 7.05);
        $response->assertJsonPath('summary.pending_events', 1);
        $response->assertJsonPath('summary.pending_records', 3);
        // The queue listing stays light; workers come with the single event.
        $response->assertJsonMissingPath('data.0.records');

        $eventId = $response->json('data.0.audit_id');

        $this->actingAs($this->hr(), 'sanctum')
            ->getJson("/api/overrides/{$eventId}")
            ->assertOk()
            ->assertJsonCount(3, 'data.records')
            ->assertJsonPath('data.records.0.credited_minutes', 140);
    }

    public function test_one_event_is_built_across_separate_syncs(): void
    {
        $first = [$this->worker(), $this->worker()];
        $late = $this->worker();

        $batch = $this->creditBatch(array_map(fn ($w) => $w->employee_id, $first));
        $this->sync($batch)->assertOk();

        // The rest of the crew, synced later — continuing the same chain.
        $this->sync($this->creditBatch([$late->employee_id], 145, end($batch)['hmac_hash'], 86_400_000 + 5 * self::MINUTE))
            ->assertOk();

        $this->assertSame(1, AuditLog::where('action_type', AuditLog::LATE_OVERRIDE)->count());

        $event = AuditLog::where('action_type', AuditLog::LATE_OVERRIDE)->first();
        $this->assertSame(3, $event->overriddenAttendances()->count());
        // Still the moment the credit was first applied.
        $this->assertSame($this->shiftStart() + 140 * self::MINUTE, $event->timestamp->getTimestampMs());
    }

    public function test_overridden_records_are_held_out_of_payroll_until_hr_approves(): void
    {
        $worker = $this->worker();
        $this->sync($this->creditBatch([$worker->employee_id]))->assertOk();

        $row = Attendance::where('employee_id', $worker->employee_id)->first();
        $this->assertFalse($row->isPayrollReady(), 'Nobody has checked the credited time yet.');
        $this->assertNull($row->effectiveTimeIn());

        $event = AuditLog::where('action_type', AuditLog::LATE_OVERRIDE)->first();

        $this->actingAs($this->hr(), 'sanctum')
            ->postJson("/api/overrides/{$event->audit_id}/approve")
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonCount(1, 'data.records');

        $row->refresh();
        $this->assertTrue($row->isPayrollReady());
        $this->assertSame($this->shiftStart(), $row->effectiveTimeIn()->getTimestampMs());
    }

    /** "Rejecting reverts to the actual tap times." */
    public function test_rejecting_pays_from_the_real_tap_and_requires_a_reason(): void
    {
        $worker = $this->worker();
        $this->sync($this->creditBatch([$worker->employee_id]))->assertOk();
        $event = AuditLog::where('action_type', AuditLog::LATE_OVERRIDE)->first();

        $this->actingAs($this->hr(), 'sanctum')
            ->postJson("/api/overrides/{$event->audit_id}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->actingAs($this->hr(), 'sanctum')
            ->postJson("/api/overrides/{$event->audit_id}/reject", ['note' => 'Gate log shows the crew arrived at 09:10.'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'rejected');

        $row = Attendance::where('employee_id', $worker->employee_id)->first();
        $this->assertTrue($row->isPayrollReady());
        $this->assertSame($this->shiftStart() + 140 * self::MINUTE, $row->effectiveTimeIn()->getTimestampMs());
        // Nothing was rewritten: the credit is still on record beside the decision.
        $this->assertSame($this->shiftStart(), $row->time_in->getTimestampMs());
    }

    public function test_only_hr_can_decide(): void
    {
        $this->sync($this->creditBatch([$this->worker()->employee_id]))->assertOk();
        $event = AuditLog::where('action_type', AuditLog::LATE_OVERRIDE)->first();

        foreach (['engineer', 'admin', 'foreman'] as $role) {
            $this->actingAs($this->loginUser($role), 'sanctum')
                ->postJson("/api/overrides/{$event->audit_id}/approve")
                ->assertForbidden();
        }

        $this->actingAs($this->loginUser('executive'), 'sanctum')
            ->getJson('/api/overrides')
            ->assertForbidden();
    }

    public function test_a_foreman_sees_only_their_own_overrides(): void
    {
        $this->sync($this->creditBatch([$this->worker()->employee_id]))->assertOk();

        $otherForeman = $this->loginUser('foreman');
        $otherCrew = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $otherForeman->employee_id,
            'status' => 'deployed',
        ]);
        $otherEvent = AuditLog::create([
            'actor_id' => $otherForeman->employee_id,
            'crew_id' => $otherCrew->crew_id,
            'subject_date' => self::DATE,
            'action_type' => AuditLog::LATE_OVERRIDE,
            'timestamp' => now(),
            'review_status' => AuditLog::REVIEW_PENDING,
        ]);
        Attendance::create([
            'employee_id' => $this->worker()->employee_id,
            'crew_id' => $otherCrew->crew_id,
            'date' => self::DATE,
            'status' => 'present',
            'sync_status' => 'synced',
            'override_flag' => 'shift_credit',
            'override_audit_id' => $otherEvent->audit_id,
        ]);

        $this->actingAs($this->foreman, 'sanctum')
            ->getJson('/api/overrides')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor.employee_id', $this->foreman->employee_id);

        $this->actingAs($this->foreman, 'sanctum')
            ->getJson("/api/overrides/{$otherEvent->audit_id}")
            ->assertNotFound();

        // HR sees both.
        $this->actingAs($this->hr(), 'sanctum')
            ->getJson('/api/overrides')
            ->assertJsonCount(2, 'data');
    }

    /** An approval covers the records HR could see — not ones that arrive afterwards. */
    public function test_a_record_synced_after_approval_reopens_the_event(): void
    {
        $batch = $this->creditBatch([$this->worker()->employee_id]);
        $this->sync($batch)->assertOk();

        $event = AuditLog::where('action_type', AuditLog::LATE_OVERRIDE)->first();
        $this->actingAs($this->hr(), 'sanctum')->postJson("/api/overrides/{$event->audit_id}/approve")->assertOk();

        $this->sync($this->creditBatch([$this->worker()->employee_id], 150, end($batch)['hmac_hash'], 86_400_000 + 10 * self::MINUTE))
            ->assertOk();

        $event->refresh();
        $this->assertSame(AuditLog::REVIEW_PENDING, $event->review_status);
        $this->assertNull($event->reviewed_by);
        $this->assertStringContainsString('Reopened', $event->description);

        // And the earlier-approved worker is held back again until re-reviewed.
        $this->assertFalse($event->overriddenAttendances()->first()->isPayrollReady());
    }

    /** Re-marking a worker by hand supersedes the credit for that worker. */
    public function test_an_ordinary_retap_takes_the_worker_out_of_the_override(): void
    {
        $worker = $this->worker();
        $credit = $this->creditBatch([$worker->employee_id]);
        $this->sync($credit)->assertOk();

        $retapAt = $this->shiftStart() + 150 * self::MINUTE;
        $retap = $this->buildBatch([$worker->employee_id], end($credit)['hmac_hash'], [
            0 => [
                'date' => self::DATE,
                'status' => 'late',
                'time_in' => $retapAt,
                'captured_at' => $retapAt,
                'monotonic_timestamp' => 86_400_000 + 10 * self::MINUTE,
            ],
        ]);
        $this->sync($retap)->assertOk();

        $row = Attendance::where('employee_id', $worker->employee_id)->first();
        $this->assertNull($row->override_flag);
        $this->assertNull($row->override_audit_id);

        // Nothing left to review, so it drops out of the queue.
        $this->actingAs($this->hr(), 'sanctum')
            ->getJson('/api/overrides')
            ->assertJsonCount(0, 'data');
    }

    /** The phone offers the override offline, so it caches the rules with the roster. */
    public function test_the_roster_carries_the_shift_rules(): void
    {
        $this->actingAs($this->foreman, 'sanctum')
            ->getJson('/api/me/crew')
            ->assertOk()
            ->assertJsonPath('shift.start', '07:00')
            ->assertJsonPath('shift.late_override_grace_minutes', 15)
            ->assertJsonPath('shift.timezone', 'Asia/Manila')
            ->assertJsonPath('shift.utc_offset_minutes', 480);
    }
}
