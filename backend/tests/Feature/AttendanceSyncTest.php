<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Crew;
use App\Models\CryptoSignature;
use App\Models\DeviceKey;
use App\Models\Employee;
use App\Services\Attendance\TimeInPolicy;
use App\Services\Crypto\AttendancePayload;
use Database\Factories\DeviceKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * End-to-end ingestion (Phase 5, layer 4) — the first point at which TC-01,
 * TC-02 and TC-03 can all be exercised together, through the real endpoint
 * rather than against the verifiers in isolation.
 *
 * The private key here is the throwaway pair matching
 * DeviceKeyFactory::TEST_PUBLIC_KEY_PEM. Test-only, never used anywhere real.
 */
class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSignedDevice();
    }

    public function test_a_valid_batch_is_accepted_and_committed(): void
    {
        $workers = [$this->worker(), $this->worker(), $this->worker()];
        $events = $this->buildBatch(array_map(fn ($w) => $w->employee_id, $workers));

        $this->sync($events)
            ->assertOk()
            ->assertJsonPath('accepted', 3)
            ->assertJsonPath('rejected', 0);

        $this->assertSame(3, Attendance::count());
        $this->assertSame(3, CryptoSignature::where('verified', true)->count());

        // The chain tip advanced, so the next batch must continue from here.
        $this->assertSame(
            end($events)['hmac_hash'],
            $this->device->fresh()->last_chain_hash,
        );
    }

    /** TC-02: edit row 2's time_in without recomputing hashes. */
    public function test_tampering_with_the_middle_event_rejects_it_and_everything_after(): void
    {
        $workers = [$this->worker(), $this->worker(), $this->worker()];
        $events = $this->buildBatch(array_map(fn ($w) => $w->employee_id, $workers));

        $tamperedTime = $events[1]['time_in'] - (75 * 60 * 1000);
        $events[1]['time_in'] = $tamperedTime;

        $response = $this->sync($events)->assertStatus(207);

        $response->assertJsonPath('accepted', 1);
        $response->assertJsonPath('rejected', 2);
        $response->assertJsonPath('results.0.accepted', true);
        $response->assertJsonPath('results.1.reason', 'hmac_mismatch');
        $response->assertJsonPath('results.2.reason', 'chain_broken_upstream');

        // The point of the exercise: no falsified time reaches tbl_attendance.
        $this->assertSame(1, Attendance::count());
        $this->assertDatabaseMissing('attendances', [
            'employee_id' => $workers[1]->employee_id,
        ]);
    }

    public function test_rejections_are_audit_logged_rather_than_silent(): void
    {
        $workers = [$this->worker(), $this->worker()];
        $events = $this->buildBatch(array_map(fn ($w) => $w->employee_id, $workers));
        $events[1]['time_in'] -= 60_000;

        $this->sync($events)->assertStatus(207);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->foreman->employee_id,
            'action_type' => 'ATTENDANCE_VERIFICATION_FAILED',
        ]);
    }

    /** TC-03 attempt 1: payload altered in transit, signature untouched. */
    public function test_payload_altered_after_signing_fails_signature_verification(): void
    {
        $worker = $this->worker();
        $events = $this->buildBatch([$worker->employee_id]);

        // Alter a field and fix the HMAC, so the chain passes and only the
        // signature can catch it. Without repairing the HMAC this would be
        // indistinguishable from TC-02.
        $events[0]['employee_id'] = $this->worker()->employee_id;
        $events[0]['hmac_hash'] = hash_hmac(
            'sha256',
            AttendancePayload::canonicalize(collect($events[0])->except(['hmac_hash', 'ecdsa_signature'])->all()),
            base64_decode($this->hmacKeyBase64),
        );

        $this->sync($events)
            ->assertStatus(207)
            ->assertJsonPath('results.0.reason', 'signature_mismatch');

        $this->assertSame(0, Attendance::count());
    }

    /** TC-03 attempt 2: valid signature from a key generated off-device. */
    public function test_signature_from_an_unregistered_key_is_rejected(): void
    {
        $worker = $this->worker();

        $events = $this->buildBatch(
            [$worker->employee_id],
            null,
            [],
            self::ATTACKER_PRIVATE_PEM,
        );

        $this->sync($events)
            ->assertStatus(207)
            ->assertJsonPath('results.0.reason', 'signature_mismatch');

        $this->assertSame(0, Attendance::count());
    }

    /** A rollback override: five real minutes on the counter, ~2h earlier on the wall. */
    private function rollbackOverride(): array
    {
        return [
            'time_in' => 1789200000000 - (115 * 60 * 1000),
            'monotonic_timestamp' => 86_400_000 + (5 * 60 * 1000),
        ];
    }

    /**
     * TC-01, per the STD's own expected result: the record is FLAGGED rather
     * than trusted, verified = false is recorded, an audit entry is written,
     * and "the hash chain remains continuous".
     *
     * This test previously asserted the record was rejected and absent — which
     * contradicted the STD and meant one clock drift orphaned every later event.
     */
    public function test_clock_rollback_is_flagged_not_rejected(): void
    {
        $workers = [$this->worker(), $this->worker()];

        $events = $this->buildBatch(
            array_map(fn ($w) => $w->employee_id, $workers),
            null,
            [1 => $this->rollbackOverride()],
        );

        $response = $this->sync($events)->assertStatus(207);

        $response->assertJsonPath('accepted', 1);
        $response->assertJsonPath('flagged', 1);
        $response->assertJsonPath('rejected', 0);
        $response->assertJsonPath('results.0.status', 'accepted');
        $response->assertJsonPath('results.1.status', 'flagged');
        $response->assertJsonPath('results.1.reason', 'wall_clock_rolled_back');

        // Committed, but explicitly NOT trusted.
        $flagged = Attendance::where('employee_id', $workers[1]->employee_id)->first();
        $this->assertNotNull($flagged, 'A flagged record is kept for HR review, not discarded.');
        $this->assertFalse($flagged->cryptoSignature->verified);

        $this->assertDatabaseHas('audit_logs', ['action_type' => 'ATTENDANCE_CLOCK_FLAGGED']);
    }

    /** The substance of TC-01's "the hash chain remains continuous". */
    public function test_the_chain_stays_continuous_through_a_flagged_event(): void
    {
        $workers = [$this->worker(), $this->worker(), $this->worker()];

        $events = $this->buildBatch(
            array_map(fn ($w) => $w->employee_id, $workers),
            null,
            [
                1 => $this->rollbackOverride(),
                // Event 2 is honest, its clock consistent with event 0.
                2 => ['time_in' => 1789200000000 + (10 * 60 * 1000), 'monotonic_timestamp' => 86_400_000 + (10 * 60 * 1000)],
            ],
        );

        $response = $this->sync($events)->assertStatus(207);

        // If a flag broke the chain, event 2 would be chain_broken_upstream.
        $response->assertJsonPath('results.2.status', 'accepted');
        $this->assertSame(end($events)['hmac_hash'], $this->device->fresh()->last_chain_hash);
    }

    public function test_a_flagged_reading_does_not_become_the_clock_baseline(): void
    {
        // If the rolled-back reading became the baseline, the NEXT event could
        // share the falsified clock and look like perfectly normal drift — the
        // rollback would be laundered. The next event must be compared against
        // the last TRUSTED reading instead.
        $workers = [$this->worker(), $this->worker(), $this->worker()];

        $rolled = $this->rollbackOverride();

        $events = $this->buildBatch(
            array_map(fn ($w) => $w->employee_id, $workers),
            null,
            [
                1 => $rolled,
                // Consistent with the ROLLED clock (+1 min on both), but still
                // ~2h behind the last trusted reading.
                2 => [
                    'time_in' => $rolled['time_in'] + 60_000,
                    'monotonic_timestamp' => $rolled['monotonic_timestamp'] + 60_000,
                ],
            ],
        );

        $this->sync($events)
            ->assertStatus(207)
            ->assertJsonPath('results.2.status', 'flagged');
    }

    public function test_a_flagged_event_never_overwrites_a_verified_time(): void
    {
        // "No falsified time is committed as the effective attendance time."
        $worker = $this->worker();

        $events = $this->buildBatch([$worker->employee_id, $worker->employee_id], null, [
            1 => array_merge($this->rollbackOverride(), ['status' => 'late']),
        ]);

        $this->sync($events)->assertStatus(207)->assertJsonPath('results.1.status', 'flagged');

        $attendance = Attendance::where('employee_id', $worker->employee_id)->first();

        $this->assertSame('present', $attendance->status, 'The flagged event replaced a trusted record.');
        $this->assertTrue($attendance->cryptoSignature->verified);
    }

    public function test_clock_continuity_is_enforced_across_batches(): void
    {
        // The first event of a later batch is the one a naive implementation
        // never checks, so a rollback between two syncs would slip through.
        $first = $this->worker();
        $batchOne = $this->buildBatch([$first->employee_id]);
        $this->sync($batchOne)->assertOk();

        $batchTwo = $this->buildBatch(
            [$this->worker()->employee_id],
            end($batchOne)['hmac_hash'],
            [0 => $this->rollbackOverride()],
        );

        $this->sync($batchTwo)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', 'flagged')
            ->assertJsonPath('results.0.reason', 'wall_clock_rolled_back');
    }

    /**
     * Regression for a latent bug: the chain was precomputed independently of
     * the signature gate, so an event with a bad signature still let its
     * successor pass linkage, and the tip jumped straight past the rejected
     * event. Every TC-03 test used a single-event batch, so it never showed.
     */
    public function test_a_bad_signature_orphans_its_successors_instead_of_being_skipped(): void
    {
        $workers = [$this->worker(), $this->worker(), $this->worker()];
        $events = $this->buildBatch(array_map(fn ($w) => $w->employee_id, $workers));

        // Corrupt only event 1's signature; its HMAC and linkage stay valid.
        $events[1]['ecdsa_signature'] = base64_encode(str_repeat("\x30", 70));

        $response = $this->sync($events)->assertStatus(207);

        $response->assertJsonPath('results.0.status', 'accepted');
        $response->assertJsonPath('results.1.status', 'rejected');
        $response->assertJsonPath('results.2.status', 'rejected');
        $response->assertJsonPath('results.2.reason', 'chain_broken_upstream');

        // The tip must stop at event 0, not leap to event 2.
        $this->assertSame($events[0]['hmac_hash'], $this->device->fresh()->last_chain_hash);
        $this->assertSame(1, Attendance::count());
    }

    public function test_status_reports_the_server_chain_tip(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id, $this->worker()->employee_id]);
        $this->sync($events)->assertOk();

        $this->actingAs($this->foreman, 'sanctum')
            ->getJson('/api/attendance/sync/status?device_id='.$this->device->device_id)
            ->assertOk()
            ->assertJsonPath('last_chain_hash', end($events)['hmac_hash']);
    }

    public function test_status_is_null_for_a_device_that_has_never_synced(): void
    {
        $this->actingAs($this->foreman, 'sanctum')
            ->getJson('/api/attendance/sync/status?device_id='.$this->device->device_id)
            ->assertOk()
            ->assertJsonPath('last_chain_hash', null);
    }

    public function test_status_refuses_someone_elses_device(): void
    {
        $other = $this->loginUser('foreman');
        DeviceKey::factory()->create(['employee_id' => $other->employee_id, 'device_id' => 'dev-theirs-0002']);

        $this->actingAs($this->foreman, 'sanctum')
            ->getJson('/api/attendance/sync/status?device_id=dev-theirs-0002')
            ->assertStatus(403);
    }

    /**
     * The lost-response case the status endpoint exists for: the server
     * commits a batch, the response never arrives, and the device retries the
     * same batch. Without reconciliation every event fails, and the device
     * would mark genuinely accepted records as rejected.
     */
    public function test_replaying_an_already_committed_batch_fails_which_is_why_status_exists(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id]);

        $this->sync($events)->assertOk();

        // Blind retry of the identical batch.
        $this->sync($events)
            ->assertStatus(207)
            ->assertJsonPath('results.0.reason', 'prev_hash_mismatch');

        // The data is safe — the first commit stands, nothing duplicated.
        $this->assertSame(1, Attendance::count());
    }

    public function test_a_batch_that_ignores_prior_history_is_rejected(): void
    {
        $first = $this->worker();
        $this->sync($this->buildBatch([$first->employee_id]))->assertOk();

        // Second batch restarts the chain from null instead of continuing.
        $replay = $this->buildBatch([$this->worker()->employee_id], null);

        $this->sync($replay)
            ->assertStatus(207)
            ->assertJsonPath('results.0.reason', 'prev_hash_mismatch');
    }

    public function test_a_reboot_is_not_mistaken_for_tampering(): void
    {
        $workers = [$this->worker(), $this->worker()];

        $events = $this->buildBatch(
            array_map(fn ($w) => $w->employee_id, $workers),
            null,
            [
                // New boot session: the counter legitimately restarts near 0
                // while the wall clock keeps going.
                1 => ['boot_id' => 'bc8', 'monotonic_timestamp' => 12_000],
            ],
        );

        $this->sync($events)
            ->assertOk()
            ->assertJsonPath('accepted', 2);
    }

    public function test_later_events_for_one_employee_collapse_into_a_single_attendance_row(): void
    {
        // The append-only log means re-tapping produces a second event for the
        // same employee and day; tbl_attendance holds one row, last write wins.
        $worker = $this->worker();

        $events = $this->buildBatch([$worker->employee_id, $worker->employee_id], null, [
            1 => ['status' => 'late'],
        ]);

        $this->sync($events)->assertOk()->assertJsonPath('accepted', 2);

        $this->assertSame(1, Attendance::count());
        $this->assertSame('late', Attendance::first()->status);
        // 1:1 per the ERD — the signature is the last accepted event's.
        $this->assertSame(1, CryptoSignature::count());
        $this->assertSame(end($events)['hmac_hash'], CryptoSignature::first()->hmac_hash);
    }

    public function test_absent_worker_is_committed_with_no_time_in(): void
    {
        $worker = $this->worker();

        $events = $this->buildBatch([$worker->employee_id], null, [
            0 => ['status' => 'absent', 'time_in' => null],
        ]);

        $this->sync($events)->assertOk();

        $this->assertNull(Attendance::first()->time_in);
        $this->assertSame('absent', Attendance::first()->status);
    }

    public function test_a_revoked_device_cannot_sync_even_with_valid_signatures(): void
    {
        // Revocation has to be enforced here: the device keeps its keypair and
        // would still produce perfectly valid signatures.
        $this->device->update(['revoked_at' => now()]);

        $this->sync($this->buildBatch([$this->worker()->employee_id]))
            ->assertStatus(403)
            ->assertJsonPath('reason', 'device_revoked');
    }

    public function test_syncing_against_someone_elses_device_is_refused(): void
    {
        $otherForeman = $this->loginUser('foreman');
        DeviceKey::factory()->create([
            'employee_id' => $otherForeman->employee_id,
            'device_id' => 'dev-theirs-0001',
        ]);

        $this->actingAs($this->foreman, 'sanctum')
            ->postJson('/api/attendance/sync', [
                'device_id' => 'dev-theirs-0001',
                'events' => $this->buildBatch([$this->worker()->employee_id]),
            ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'device_not_bound');
    }

    public function test_non_foreman_roles_cannot_sync(): void
    {
        $hr = $this->loginUser('hr');

        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/attendance/sync', [
                'device_id' => $this->device->device_id,
                'events' => $this->buildBatch([$this->worker()->employee_id]),
            ])
            ->assertForbidden();
    }

    public function test_missing_time_in_key_is_a_validation_error_not_a_silent_null(): void
    {
        // A dropped key would change the canonical payload and break the
        // signature, so it must fail loudly at the boundary.
        $events = $this->buildBatch([$this->worker()->employee_id]);
        unset($events[0]['time_in']);

        $this->sync($events)->assertStatus(422)->assertJsonValidationErrors('events.0.time_in');
    }

    /* -------------------------------------------------------------------- *
     * Phase 7 — payload v2, TimeInPolicy, crew leadership
     * -------------------------------------------------------------------- */

    /** 07:00 site time on the batch date, in epoch ms. */
    private function shiftStartMs(): int
    {
        return app(TimeInPolicy::class)->shiftStartMs('2026-09-12');
    }

    /**
     * The gap v2 closes. In v1 override_flag sat outside the signature, so an
     * override could be switched on in the local database or in transit
     * without breaking anything — while changing what the worker is paid.
     */
    public function test_an_override_cannot_be_added_to_an_event_after_signing(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id]);
        $events[0]['override_type'] = 'shift_credit';

        $this->sync($events)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.reason', 'hmac_mismatch');

        $this->assertSame(0, Attendance::count());
    }

    /**
     * TC-04, server side: foreman opens roll call at 09:20, crew is credited
     * 07:00. Accepted as TRUSTED data — not flagged as a clock rollback, which
     * is what the v1 clock check (against time_in) would have done.
     */
    public function test_a_late_foreman_shift_credit_is_accepted_and_credited_at_shift_start(): void
    {
        $worker = $this->worker();

        $events = $this->buildBatch([$worker->employee_id], null, [
            0 => [
                'time_in' => $this->shiftStartMs(),
                'captured_at' => $this->shiftStartMs() + (140 * 60_000), // 09:20
                'override_type' => 'shift_credit',
            ],
        ]);

        $this->sync($events)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');

        $attendance = Attendance::where('employee_id', $worker->employee_id)->first();

        $this->assertSame($this->shiftStartMs(), $attendance->time_in->getTimestampMs());
        $this->assertSame('shift_credit', $attendance->override_flag);
        $this->assertTrue($attendance->cryptoSignature->verified);
    }

    /** Continues past a credit rather than flagging the next honest tap. */
    public function test_an_ordinary_tap_after_a_shift_credit_is_not_flagged(): void
    {
        $workers = [$this->worker(), $this->worker()];
        $tap = $this->shiftStartMs() + (140 * 60_000);

        $events = $this->buildBatch(array_map(fn ($w) => $w->employee_id, $workers), null, [
            0 => ['time_in' => $this->shiftStartMs(), 'captured_at' => $tap, 'override_type' => 'shift_credit'],
            1 => ['time_in' => $tap + 60_000, 'monotonic_timestamp' => 86_400_000 + 60_000],
        ]);

        $this->sync($events)
            ->assertOk()
            ->assertJsonPath('results.1.status', 'accepted');
    }

    /**
     * A refused event is authentic but not permitted. It must not be committed,
     * and it must NOT break the chain: its successors still link and are
     * accepted, instead of every later event becoming chain_broken_upstream.
     */
    public function test_a_shift_credit_for_the_wrong_time_is_refused_without_breaking_the_chain(): void
    {
        $workers = [$this->worker(), $this->worker()];

        $events = $this->buildBatch(array_map(fn ($w) => $w->employee_id, $workers), null, [
            0 => [
                'time_in' => $this->shiftStartMs() - (60 * 60_000), // 06:00, not 07:00
                'captured_at' => 1789200000000,
                'override_type' => 'shift_credit',
            ],
        ]);

        $response = $this->sync($events)->assertStatus(207);

        $response->assertJsonPath('refused', 1);
        $response->assertJsonPath('results.0.status', 'refused');
        $response->assertJsonPath('results.0.reason', 'shift_credit_time_not_shift_start');
        $response->assertJsonPath('results.1.status', 'accepted');

        $this->assertNull(Attendance::where('employee_id', $workers[0]->employee_id)->first());
        $this->assertSame(end($events)['hmac_hash'], $this->device->fresh()->last_chain_hash);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'ATTENDANCE_REFUSED']);
    }

    /**
     * With the clock check on captured_at, time_in would otherwise be unchecked.
     * An ordinary tap cannot credit an earlier arrival than when it happened.
     */
    public function test_an_ordinary_tap_cannot_backdate_its_time_in(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id], null, [
            0 => ['time_in' => 1789200000000 - (90 * 60_000), 'captured_at' => 1789200000000],
        ]);

        $this->sync($events)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', 'refused')
            ->assertJsonPath('results.0.reason', 'time_in_does_not_match_tap');

        $this->assertSame(0, Attendance::count());
    }

    /**
     * TC-05, server side: once a crew has been handed to another foreman, the
     * original foreman's phone can no longer submit roll call for it — even
     * though the event is genuine and correctly signed.
     */
    public function test_a_foreman_who_no_longer_leads_the_crew_is_refused(): void
    {
        $otherForeman = $this->loginUser('foreman');
        Crew::whereKey($this->crewId)->update(['foreman_id' => $otherForeman->employee_id]);

        $events = $this->buildBatch([$this->worker()->employee_id, $this->worker()->employee_id]);

        $response = $this->sync($events)->assertStatus(207);

        $response->assertJsonPath('refused', 2);
        $response->assertJsonPath('results.0.reason', 'not_crew_foreman');
        // Not chain_broken_upstream: a permission problem does not orphan the chain.
        $response->assertJsonPath('results.1.reason', 'not_crew_foreman');

        $this->assertSame(0, Attendance::count());
        $this->assertSame(end($events)['hmac_hash'], $this->device->fresh()->last_chain_hash);
    }

    public function test_captured_at_is_required(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id]);
        unset($events[0]['captured_at']);

        $this->sync($events)->assertStatus(422)->assertJsonValidationErrors('events.0.captured_at');
    }

    /* ---------------------------------------------------------------------
     | Payload v3 (time-out capture)
     * --------------------------------------------------------------------- */

    public function test_a_v3_roll_call_is_accepted(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id], null, [['payload_version' => 'v3']]);

        $this->sync($events)->assertOk()->assertJsonPath('accepted', 1);
    }

    /** v2 events still queued on a phone when it updates must still verify. */
    public function test_v2_and_v3_events_verify_in_one_chain(): void
    {
        $events = $this->buildBatch(
            [$this->worker()->employee_id, $this->worker()->employee_id],
            null,
            [1 => ['payload_version' => 'v3']],
        );

        // Also the regression for request validation reordering the batch:
        // only the second event carries payload_version.
        $this->sync($events)->assertOk()->assertJsonPath('accepted', 2);
    }

    public function test_relabelling_a_v3_event_as_v2_breaks_its_hmac(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id], null, [['payload_version' => 'v3']]);
        $events[0]['payload_version'] = 'v2';

        $this->sync($events)->assertJsonPath('rejected', 1)->assertJsonPath('results.0.reason', 'hmac_mismatch');
    }

    public function test_a_v3_event_must_send_its_v3_fields(): void
    {
        $events = $this->buildBatch([$this->worker()->employee_id], null, [['payload_version' => 'v3']]);
        unset($events[0]['time_out']);

        $this->sync($events)->assertUnprocessable();
    }
}
