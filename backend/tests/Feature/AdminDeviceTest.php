<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeviceKey;
use Database\Factories\DeviceKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsAttendanceEvents;
use Tests\TestCase;

/**
 * Device & Sync Health (Phase 10) — the web portal's admin registry of bound
 * field devices. Closes the review gap that the only revoke in the system was
 * the foreman revoking their own device from that device.
 */
class AdminDeviceTest extends TestCase
{
    use RefreshDatabase;
    use SignsAttendanceEvents;

    /* ------------------------------------------------------------------ *
     *  Listing
     * ------------------------------------------------------------------ */

    public function test_hr_and_admin_can_list_every_bound_device(): void
    {
        $foreman = $this->loginUser('foreman');
        $device = DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-fleet-0001',
            'bound_at' => now()->subDays(2),
            'last_synced_at' => now()->subHours(3),
        ]);

        foreach (['hr', 'admin'] as $slug) {
            $user = $this->loginUser($slug);

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/devices')->assertOk();

            $this->assertSame('dev-fleet-0001', $response->json('devices.0.device_id'));
            $this->assertSame($foreman->employee_code, $response->json('devices.0.owner.employee_code'));
            $this->assertSame($foreman->full_name, $response->json('devices.0.owner.full_name'));
            $this->assertSame('foreman', $response->json('devices.0.owner.role'));
            $this->assertSame('TRUSTED_ENVIRONMENT', $response->json('devices.0.security_level'));
            $this->assertTrue($response->json('devices.0.hardware_backed'));
            $this->assertFalse($response->json('devices.0.has_chain_history'));
            $this->assertNull($response->json('devices.0.revoked_at'));
            $this->assertNotNull($response->json('devices.0.last_synced_at'));
        }
    }

    public function test_listing_never_leaks_hmac_hash_or_signature_material(): void
    {
        $foreman = $this->loginUser('foreman');
        DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-secret-0001',
            'public_key' => DeviceKeyFactory::TEST_PUBLIC_KEY_PEM,
            'hmac_key' => base64_encode(random_bytes(32)),
            'last_chain_hash' => str_repeat('ab', 32),
        ]);

        $admin = $this->loginUser('admin');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/devices')->assertOk();

        $response->assertJsonMissingPath('devices.0.hmac_key');
        $response->assertJsonMissingPath('devices.0.public_key');
        $response->assertJsonMissingPath('devices.0.last_chain_hash');

        $content = strtolower($response->getContent());
        $this->assertStringNotContainsString('hmac', $content);
        $this->assertStringNotContainsString('ecdsa', $content);
        $this->assertStringNotContainsString('signature', $content);
    }

    public function test_chain_history_is_reported_as_presence_not_digest(): void
    {
        $foreman = $this->loginUser('foreman');
        DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-chained-0001',
            'last_chain_hash' => str_repeat('ab', 32),
        ]);

        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('devices.0.has_chain_history', true)
            // Only the boolean may appear — never the 64-hex digest itself.
            ->assertJsonMissingPath('devices.0.last_chain_hash');
    }

    public function test_revoked_devices_are_listed_last_with_their_revoked_at(): void
    {
        $foreman = $this->loginUser('foreman');
        DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-active-0001',
            'bound_at' => now()->subDays(1),
        ]);
        DeviceKey::factory()->revoked()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-revoked-0001',
            'bound_at' => now()->subDays(5),
            'revoked_at' => now()->subDays(2),
        ]);

        $admin = $this->loginUser('admin');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/devices')->assertOk();

        $this->assertSame('dev-active-0001', $response->json('devices.0.device_id'));
        $this->assertSame('dev-revoked-0001', $response->json('devices.1.device_id'));
        $this->assertNotNull($response->json('devices.1.revoked_at'));
    }

    public function test_integrity_incidents_are_counted_per_owner_not_per_audit_row(): void
    {
        $foreman = $this->loginUser('foreman');
        $device = DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-incidents-0001',
        ]);

        // One attack day, many fallout rows — must count once (distinct day).
        foreach ([AuditLog::ATTENDANCE_CLOCK_FLAGGED, AuditLog::ATTENDANCE_VERIFICATION_FAILED] as $type) {
            AuditLog::create([
                'actor_id' => $foreman->employee_id,
                'action_type' => $type,
                'description' => "Rejected attendance for employee {$foreman->employee_id} on 2026-09-12 from device dev-incidents-0001",
                'timestamp' => now()->subDays(5),
            ]);
        }

        // A separate incident day earlier still counts.
        AuditLog::create([
            'actor_id' => $foreman->employee_id,
            'action_type' => AuditLog::ATTENDANCE_VERIFICATION_FAILED,
            'description' => 'Another failure.',
            'timestamp' => now()->subDays(20),
        ]);

        // Someone else's incident must not inflate this owner's count.
        $otherForeman = $this->loginUser('foreman');
        AuditLog::create([
            'actor_id' => $otherForeman->employee_id,
            'action_type' => AuditLog::ATTENDANCE_VERIFICATION_FAILED,
            'description' => 'Not your problem.',
            'timestamp' => now(),
        ]);

        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('devices.0.device_id', $device->device_id)
            ->assertJsonPath('devices.0.integrity_incidents', 2);
    }

    public function test_listing_is_denied_to_non_admin_roles(): void
    {
        foreach (['foreman', 'engineer', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')->getJson('/api/devices')->assertForbidden();
        }
    }

    public function test_guest_cannot_list_devices(): void
    {
        $this->getJson('/api/devices')->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ *
     *  Revocation
     * ------------------------------------------------------------------ */

    public function test_admin_can_revoke_a_device_with_a_reason(): void
    {
        $foreman = $this->loginUser('foreman');
        $device = DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-lost-0001',
        ]);
        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/devices/'.$device->device_key_id, ['reason' => 'handset lost on site'])
            ->assertOk()
            ->assertJsonPath('device_id', 'dev-lost-0001');

        $this->assertNotNull($device->fresh()->revoked_at);

        // The revocation is attributed to the person who acted — the admin —
        // and the reason lands in the audit description.
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->employee_id,
            'action_type' => 'DEVICE_REVOKED',
            'description' => 'Device dev-lost-0001 revoked: handset lost on site',
        ]);
    }

    public function test_revoke_requires_a_reason(): void
    {
        $foreman = $this->loginUser('foreman');
        $device = DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-noreason-0001',
        ]);
        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/devices/'.$device->device_key_id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_revoke_of_a_missing_device_is_a_clean_404(): void
    {
        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/devices/999999', ['reason' => 'nope'])
            ->assertNotFound();
    }

    public function test_revoke_of_an_already_revoked_device_is_refused(): void
    {
        $foreman = $this->loginUser('foreman');
        $device = DeviceKey::factory()->revoked()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-twice-0001',
        ]);
        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/devices/'.$device->device_key_id, ['reason' => 'again?'])
            ->assertStatus(422);
    }

    public function test_hr_can_view_but_not_revoke(): void
    {
        $foreman = $this->loginUser('foreman');
        $device = DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-hrvlew-0001',
        ]);
        $hr = $this->loginUser('hr');

        // HR sees the fleet...
        $this->actingAs($hr, 'sanctum')->getJson('/api/devices')->assertOk();

        // ...but revoking is an Admin write.
        $this->actingAs($hr, 'sanctum')
            ->deleteJson('/api/devices/'.$device->device_key_id, ['reason' => 'hr wants to'])
            ->assertForbidden();

        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_non_admin_roles_cannot_revoke(): void
    {
        $foreman = $this->loginUser('foreman');
        $device = DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-frozen-0001',
        ]);

        foreach (['foreman', 'engineer', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/devices/'.$device->device_key_id, ['reason' => 'sneaky'])
                ->assertForbidden();
        }

        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_guest_cannot_revoke(): void
    {
        $this->deleteJson('/api/devices/1', ['reason' => 'guest'])->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ *
     *  Ingest maintains last_synced_at (the registry's online signal)
     * ------------------------------------------------------------------ */

    public function test_sync_updates_the_devices_last_synced_at(): void
    {
        $this->setUpSignedDevice();
        $events = $this->buildBatch([$this->worker()->employee_id]);

        $this->travelTo(now());
        $this->sync($events)->assertOk();

        $this->assertNotNull($this->device->fresh()->last_synced_at);
    }
}
