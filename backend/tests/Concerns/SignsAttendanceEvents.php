<?php

namespace Tests\Concerns;

use App\Models\Crew;
use App\Models\DeviceKey;
use App\Models\Employee;
use App\Services\Crypto\AttendancePayload;
use Database\Factories\DeviceKeyFactory;
use Illuminate\Testing\TestResponse;

/**
 * A bound foreman device that produces correctly chained, correctly signed
 * attendance batches — what an untampered phone sends. Shared by every test
 * that drives the real sync endpoint (Phase 5 integrity, Phase 7 overrides).
 *
 * The private key is the throwaway pair matching
 * DeviceKeyFactory::TEST_PUBLIC_KEY_PEM. Test-only, never used anywhere real.
 */
trait SignsAttendanceEvents
{
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

    protected Employee $foreman;

    protected DeviceKey $device;

    protected string $hmacKeyBase64;

    protected int $crewId;

    protected function pem(string $indented): string
    {
        return implode("\n", array_map('trim', explode("\n", trim($indented))))."\n";
    }

    protected function worker(): Employee
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
    protected function buildBatch(
        array $employeeIds,
        ?string $startPrevHash = null,
        array $overrides = [],
        ?string $signWith = null,
    ): array {
        $events = [];
        $prevHash = $startPrevHash;
        $hmacKey = base64_decode($this->hmacKeyBase64);

        foreach (array_values($employeeIds) as $i => $employeeId) {
            $wallMs = 1789200000000 + ($i * 60_000);
            $override = $overrides[$i] ?? [];

            $payload = array_merge([
                'employee_id' => $employeeId,
                'crew_id' => $this->crewId,
                'date' => '2026-09-12',
                'status' => 'present',
                'time_in' => $wallMs,
                'captured_at' => null,
                'override_type' => null,
                'monotonic_timestamp' => 86_400_000 + ($i * 60_000),
                'boot_id' => 'bc7',
                'device_id' => $this->device->device_id,
                'prev_hash' => $prevHash,
            ], $override);

            // A v3 event carries the time-out fields too; a roll call leaves
            // them empty. Events without a version are v2, as older phones send.
            if (($payload['payload_version'] ?? 'v2') === 'v3') {
                $payload += ['event_type' => 'roll_call', 'time_out' => null, 'time_out_type' => null];
            }

            // An ordinary tap captures at its own time_in, so a test that moves
            // time_in (a clock rollback) moves captured_at with it — exactly as
            // a real Settings change would. Absent has no time_in but still a
            // real tap time. Only an explicit captured_at breaks the link.
            if (! array_key_exists('captured_at', $override)) {
                $payload['captured_at'] = $payload['time_in'] ?? $wallMs;
            }

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

    protected function sync(array $events): TestResponse
    {
        return $this->actingAs($this->foreman, 'sanctum')->postJson('/api/attendance/sync', [
            'device_id' => $this->device->device_id,
            'events' => $events,
        ]);
    }

    /** A foreman, a deployed crew they lead, and their bound device. */
    protected function setUpSignedDevice(): void
    {
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
}
