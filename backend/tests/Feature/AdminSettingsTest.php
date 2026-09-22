<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * System Settings (C6, FR-01) — read-only, admin only, built from config()
 * alone. The test pins both directions: the values in force are shown, and
 * no secret is ever returned.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_read_settings(): void
    {
        foreach (['hr', 'engineer', 'foreman', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/settings')
                ->assertForbidden();
        }

        $this->actingAs($this->loginUser('admin'), 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertOk();
    }

    public function test_settings_show_the_values_in_force(): void
    {
        $response = $this->actingAs($this->loginUser('admin'), 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertOk();

        $data = $response->json('data');

        $this->assertSame('Asia/Manila', $data['attendance']['timezone']);
        $this->assertSame('07:00', $data['attendance']['shift_start']);
        $this->assertSame('16:00', $data['attendance']['shift_end']);

        $this->assertSame([21, 6], $data['payroll']['cutoff_start_days']);
        // assertEquals, not assertSame: whole floats cross JSON as ints.
        $this->assertEquals(501, $data['payroll']['regional_minimum_wage']);
        $this->assertSame('2025-01-01', $data['payroll']['statutory_in_effect_from']['sss']);

        // The honest headline: hardware keys are not enforced yet.
        $this->assertFalse($data['integrity']['hardware_keys_required']);

        $this->assertSame(720, $data['sign_in']['web_minutes']);
        $this->assertSame(120, $data['sign_in']['portal_minutes']);
        $this->assertSame(30, $data['sign_in']['mobile_days']);

        $this->assertNotEmpty($data['cache']['store']);
    }

    public function test_no_secret_is_ever_returned(): void
    {
        $response = $this->actingAs($this->loginUser('admin'), 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertOk();

        $body = $response->getContent();

        foreach (['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'DB_HOST', 'secret', 'BEGIN ', 'PRIVATE KEY'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }
}
