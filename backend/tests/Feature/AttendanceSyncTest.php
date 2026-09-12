<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Crew;
use App\Models\CryptoSignature;
use App\Models\DeviceKey;
use App\Models\Employee;
use App\Services\Crypto\AttendancePayload;
use Database\Factories\DeviceKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    private const DEVICE_PRIVATE_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgoRXcZuXCr+JcSWBP
        coidw4Idh78Liyfxw6E+5beiGXKhRANCAARn+pE2+Owqzh6BtXSeerEGxlTL30Wk
        y9rHPYMuQ9IFYFUnejhtbK+uCzblyfWlOyB7J1SrGpYdGoyHPmj5Jjcd
        -----END PRIVATE KEY-----
        PEM;

    /** A different, unregistered P-256 key — TC-03's off-device forgery. */
    private const ATTACKER_PRIVATE_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg0co8kBslSE2LszvX
        7ElKgfZl0fSvRj8M6ky8sohyDhKhRANCAAQuPfazI66/JV+8J/Gj4WzW/dQEIjS4
        LooHIQHuVAwJoe/no1j7IA+cfS1h+lKrY+CA87TBZLd9sg/XdFTwadHL
        -----END PRIVATE KEY-----
        PEM;

    private Employee $foreman;

    private DeviceKey $device;

    private string $hmacKeyBase64;

    private int $crewId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foreman = $this->loginUser('foreman');
        $this->hmacKeyBase64 = base64_encode(random_bytes(32));

        // A real crew: attendances.crew_id is a foreign key, so a hardcoded id
        // fails the constraint rather than the verification being exercised.
        $this->crewId = Crew::factory()->create([
            'site_id' => $this->site()->site_id,
            'foreman_id' => $this->foreman->employee_id,
            'status' => 'deployed',
            'deployed_at' => now(),
        ])->crew_id;

        $this->device = DeviceKey::factory()->create([
            'employee_id' => $this->foreman->employee_id,
            'device_id' => 'dev-sync-0001',
            'public_key' => DeviceKeyFactory::TEST_PUBLIC_KEY_PEM,
            'hmac_key' => $this->hmacKeyBase64,
        ]);
    }

    private function pem(string $indented): string
    {
        return implode("\n", array_map('trim', explode("\n", trim($indented))))."\n";
    }

    private function worker(): Employee
    {
        return Employee::factory()->create([
            'role_id' => $this->role('worker')->role_id,
            'site_id' => $this->site()->site_id,
        ]);
    }

    /**
     * Build a correctly chained, correctly signed batch — what an untampered
     * device produces.
     *
     * @param  array<int, array<string, mixed>>  $overrides  per-index payload overrides
     * @return array<int, array<string, mixed>>
     */
    private function buildBatch(
        array $employeeIds,
        ?string $startPrevHash = null,
        array $overrides = [],
        ?string $signWith = null,
    ): array {
        $events = [];
        $prevHash = $startPrevHash;
        $hmacKey = base64_decode($this->hmacKeyBase64);

        foreach (array_values($employeeIds) as $i => $employeeId) {
            $payload = array_merge([
                'employee_id' => $employeeId,
                'crew_id' => $this->crewId,
                'date' => '2026-09-12',
                'status' => 'present',
                'time_in' => 1789200000000 + ($i * 60_000),
                'monotonic_timestamp' => 86_400_000 + ($i * 60_000),
                'boot_id' => 'bc7',
                'device_id' => $this->device->device_id,
                'prev_hash' => $prevHash,
            ], $overrides[$i] ?? []);

            $canonical = AttendancePayload::canonicalize($payload);

            openssl_sign(
                $canonical,
                $rawSignature,
                $this->pem($signWith ?? self::DEVICE_PRIVATE_PEM),
                'sha256',
            );

            $event = $payload;
            $event['hmac_hash'] = hash_hmac('sha256', $canonical, $hmacKey);
            $event['ecdsa_signature'] = base64_encode($rawSignature);

            $prevHash = $event['hmac_hash'];
            $events[] = $event;
        }

        return $events;
    }

    private function sync(array $events): TestResponse
    {
        return $this->actingAs($this->foreman, 'sanctum')->postJson('/api/attendance/sync', [
            'device_id' => $this->device->device_id,
            'events' => $events,
        ]);
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

    /** TC-01: wall clock rolled back two hours while the counter advanced. */
    public function test_clock_rollback_between_events_is_rejected(): void
    {
        $workers = [$this->worker(), $this->worker()];

        $events = $this->buildBatch(
            array_map(fn ($w) => $w->employee_id, $workers),
            null,
            [
                // Five real minutes pass on the monotonic counter, but the
                // wall clock reads nearly two hours earlier.
                1 => [
                    'time_in' => 1789200000000 - (115 * 60 * 1000),
                    'monotonic_timestamp' => 86_400_000 + (5 * 60 * 1000),
                ],
            ],
        );

        $response = $this->sync($events)->assertStatus(207);

        $response->assertJsonPath('results.0.accepted', true);
        $response->assertJsonPath('results.1.reason', 'wall_clock_rolled_back');

        $this->assertDatabaseMissing('attendances', [
            'employee_id' => $workers[1]->employee_id,
        ]);
    }

    public function test_clock_continuity_is_enforced_across_batches(): void
    {
        // The first event of a later batch is the one a naive implementation
        // never checks, so a rollback between two syncs would slip through.
        $first = $this->worker();
        $batchOne = $this->buildBatch([$first->employee_id]);
        $this->sync($batchOne)->assertOk();

        $second = $this->worker();
        $batchTwo = $this->buildBatch(
            [$second->employee_id],
            end($batchOne)['hmac_hash'],
            [
                0 => [
                    'time_in' => 1789200000000 - (115 * 60 * 1000),
                    'monotonic_timestamp' => 86_400_000 + (5 * 60 * 1000),
                ],
            ],
        );

        $this->sync($batchTwo)
            ->assertStatus(207)
            ->assertJsonPath('results.0.reason', 'wall_clock_rolled_back');
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
}
