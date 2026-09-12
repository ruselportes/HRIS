<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeviceKey;
use Database\Factories\DeviceKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Device binding (Phase 5) — the trust anchor TC-03 depends on ("device bound
 * to the system with a keypair generated inside the TEE, whose public key is
 * registered on the server").
 */
class DeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PEM = DeviceKeyFactory::TEST_PUBLIC_KEY_PEM;

    /** A structurally valid P-256 key that is simply a different key. */
    private const OTHER_PEM = "-----BEGIN PUBLIC KEY-----\n"
        ."MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAELj32syOuvyVfvCfxo+Fs1v3UBCI0\n"
        ."uC6KByEB7lQMCaHv56NY+yAPnH0tYfpSq2PggPO0wWS3fbIP13RU8GnRyw==\n"
        ."-----END PUBLIC KEY-----\n";

    /** P-384 — structurally fine, wrong curve for this system. */
    private const P384_PEM = "-----BEGIN PUBLIC KEY-----\n"
        ."MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAELM0BhNzgCGbtyIF4tviHDC6wFJo/9tg1\n"
        ."BuwP+ekXmf4prhl29aA8SMkhErNLtWJnjx8h9zOA8apEU/7IsWS11OPzC7yy7j2i\n"
        ."37e00I6jd8nNtCDRHS8X04a/0/8snpDf\n"
        ."-----END PUBLIC KEY-----\n";

    public function test_foreman_can_bind_a_device_and_receives_the_hmac_key_once(): void
    {
        $foreman = $this->loginUser('foreman');

        $response = $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-abc123-0001',
                'public_key' => self::VALID_PEM,
                'security_level' => 'TRUSTED_ENVIRONMENT',
            ])
            ->assertCreated();

        $response->assertJsonPath('device_id', 'dev-abc123-0001');
        $response->assertJsonPath('hardware_backed', true);
        $response->assertJsonPath('rebound', false);

        $hmacKey = $response->json('hmac_key');
        $this->assertNotEmpty($hmacKey);
        // 32 raw bytes, base64 encoded.
        $this->assertSame(32, strlen(base64_decode($hmacKey, true)));

        $this->assertDatabaseHas('device_keys', [
            'device_id' => 'dev-abc123-0001',
            'employee_id' => $foreman->employee_id,
        ]);
    }

    public function test_hmac_key_is_never_returned_by_the_listing_endpoint(): void
    {
        $foreman = $this->loginUser('foreman');
        DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-listed-0001',
        ]);

        $response = $this->actingAs($foreman, 'sanctum')
            ->getJson('/api/me/devices')
            ->assertOk();

        $response->assertJsonPath('devices.0.device_id', 'dev-listed-0001');
        $response->assertJsonMissingPath('devices.0.hmac_key');
        $this->assertStringNotContainsString('hmac', strtolower($response->getContent()));
    }

    public function test_hmac_key_is_stored_encrypted_not_in_plaintext(): void
    {
        $foreman = $this->loginUser('foreman');

        $hmacKey = $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-enc-0001',
                'public_key' => self::VALID_PEM,
                'security_level' => 'TRUSTED_ENVIRONMENT',
            ])
            ->json('hmac_key');

        $stored = DB::table('device_keys')->where('device_id', 'dev-enc-0001')->value('hmac_key');

        $this->assertNotSame($hmacKey, $stored, 'The shared secret is sitting in the column in plaintext.');

        // But the model still round-trips it.
        $this->assertSame($hmacKey, DeviceKey::where('device_id', 'dev-enc-0001')->first()->hmac_key);
    }

    public function test_rebinding_issues_a_new_key_and_resets_the_chain(): void
    {
        $foreman = $this->loginUser('foreman');

        $first = $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-rebind-0001',
                'public_key' => self::VALID_PEM,
                'security_level' => 'TRUSTED_ENVIRONMENT',
            ])
            ->assertCreated()
            ->json('hmac_key');

        // Simulate accumulated chain history before the rebind.
        DeviceKey::where('device_id', 'dev-rebind-0001')->update(['last_chain_hash' => str_repeat('a', 64)]);

        $second = $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-rebind-0001',
                'public_key' => self::OTHER_PEM,
                'security_level' => 'STRONGBOX',
            ])
            ->assertOk()
            ->assertJsonPath('rebound', true)
            ->json('hmac_key');

        $this->assertNotSame($first, $second, 'A rebind must not inherit the previous secret.');

        $device = DeviceKey::where('device_id', 'dev-rebind-0001')->first();
        $this->assertNull(
            $device->last_chain_hash,
            'The old chain was keyed on a discarded secret, so it cannot legitimately continue.'
        );
        $this->assertSame(1, DeviceKey::where('device_id', 'dev-rebind-0001')->count());
    }

    public function test_every_bind_is_audit_logged(): void
    {
        $foreman = $this->loginUser('foreman');

        $this->actingAs($foreman, 'sanctum')->postJson('/api/me/devices', [
            'device_id' => 'dev-audit-0001',
            'public_key' => self::VALID_PEM,
            'security_level' => 'TRUSTED_ENVIRONMENT',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $foreman->employee_id,
            'action_type' => 'DEVICE_BOUND',
        ]);

        $this->actingAs($foreman, 'sanctum')->postJson('/api/me/devices', [
            'device_id' => 'dev-audit-0001',
            'public_key' => self::OTHER_PEM,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $foreman->employee_id,
            'action_type' => 'DEVICE_REBOUND',
        ]);
    }

    public function test_device_moving_between_foremen_is_recorded_in_the_audit_description(): void
    {
        // The case most worth finding later: a handover if legitimate, an
        // account compromise if not.
        $first = $this->loginUser('foreman');
        $second = $this->loginUser('foreman');

        $this->actingAs($first, 'sanctum')->postJson('/api/me/devices', [
            'device_id' => 'dev-handover-0001',
            'public_key' => self::VALID_PEM,
        ])->assertCreated();

        $this->actingAs($second, 'sanctum')->postJson('/api/me/devices', [
            'device_id' => 'dev-handover-0001',
            'public_key' => self::OTHER_PEM,
        ])->assertOk();

        $log = AuditLog::where('action_type', 'DEVICE_REBOUND')->latest('audit_id')->first();

        $this->assertStringContainsString("Reassigned from employee {$first->employee_id}", $log->description);
        $this->assertSame($second->employee_id, DeviceKey::where('device_id', 'dev-handover-0001')->first()->employee_id);
    }

    public function test_wrong_curve_key_is_refused(): void
    {
        $foreman = $this->loginUser('foreman');

        $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-curve-0001',
                'public_key' => self::P384_PEM,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'public_key_wrong_curve');

        $this->assertDatabaseMissing('device_keys', ['device_id' => 'dev-curve-0001']);
    }

    public function test_unreadable_key_is_refused(): void
    {
        $foreman = $this->loginUser('foreman');

        $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-bad-0001',
                'public_key' => 'not a pem at all',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'public_key_unreadable');
    }

    public function test_device_id_containing_a_line_break_is_refused(): void
    {
        // It would forge a field boundary in the signed canonical payload.
        $foreman = $this->loginUser('foreman');

        $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => "dev-x\nprev_hash=deadbeef",
                'public_key' => self::VALID_PEM,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_id');
    }

    public function test_software_backed_key_is_refused_when_hardware_is_required(): void
    {
        config(['crypto.require_hardware_backed_keys' => true]);

        $foreman = $this->loginUser('foreman');

        $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-emulator-0001',
                'public_key' => self::VALID_PEM,
                'security_level' => 'SOFTWARE',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'security_level_not_accepted');
    }

    public function test_software_backed_key_is_allowed_when_hardware_is_not_required(): void
    {
        // The emulator development path.
        config(['crypto.require_hardware_backed_keys' => false]);

        $foreman = $this->loginUser('foreman');

        $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/me/devices', [
                'device_id' => 'dev-emulator-0002',
                'public_key' => self::VALID_PEM,
                'security_level' => 'SOFTWARE',
            ])
            ->assertCreated()
            ->assertJsonPath('hardware_backed', false);
    }

    public function test_foreman_can_revoke_their_own_device(): void
    {
        $foreman = $this->loginUser('foreman');
        DeviceKey::factory()->create([
            'employee_id' => $foreman->employee_id,
            'device_id' => 'dev-revoke-0001',
        ]);

        $this->actingAs($foreman, 'sanctum')
            ->deleteJson('/api/me/devices/dev-revoke-0001', ['reason' => 'handset lost'])
            ->assertOk();

        $this->assertNotNull(DeviceKey::where('device_id', 'dev-revoke-0001')->first()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'DEVICE_REVOKED']);
    }

    public function test_foreman_cannot_revoke_someone_elses_device(): void
    {
        $foreman = $this->loginUser('foreman');
        $otherForeman = $this->loginUser('foreman');

        DeviceKey::factory()->create([
            'employee_id' => $otherForeman->employee_id,
            'device_id' => 'dev-notmine-0001',
        ]);

        $this->actingAs($foreman, 'sanctum')
            ->deleteJson('/api/me/devices/dev-notmine-0001')
            ->assertNotFound();

        $this->assertNull(DeviceKey::where('device_id', 'dev-notmine-0001')->first()->revoked_at);
    }

    public function test_listing_only_shows_the_authenticated_foremans_devices(): void
    {
        $foreman = $this->loginUser('foreman');
        $otherForeman = $this->loginUser('foreman');

        DeviceKey::factory()->create(['employee_id' => $foreman->employee_id, 'device_id' => 'dev-mine']);
        DeviceKey::factory()->create(['employee_id' => $otherForeman->employee_id, 'device_id' => 'dev-theirs']);

        $response = $this->actingAs($foreman, 'sanctum')->getJson('/api/me/devices')->assertOk();

        $this->assertCount(1, $response->json('devices'));
        $this->assertSame('dev-mine', $response->json('devices.0.device_id'));
    }

    public function test_non_foreman_roles_cannot_bind_devices(): void
    {
        foreach (['hr', 'engineer', 'admin', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/me/devices', [
                    'device_id' => "dev-{$slug}-0001",
                    'public_key' => self::VALID_PEM,
                ])
                ->assertForbidden();
        }
    }

    public function test_guest_cannot_bind_a_device(): void
    {
        $this->postJson('/api/me/devices', [
            'device_id' => 'dev-guest-0001',
            'public_key' => self::VALID_PEM,
        ])->assertUnauthorized();
    }
}
