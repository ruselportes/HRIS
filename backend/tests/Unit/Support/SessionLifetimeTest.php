<?php

namespace Tests\Unit\Support;

use App\Models\Employee;
use App\Support\SessionLifetime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The server decides sign-in lifetimes from the account's role and the
 * client hint — a request parameter alone must never buy a longer session.
 * Pinned directly (not only through login) per review: staff from web and
 * from mobile, a foreman from web and from mobile, a worker and an operator.
 */
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('lifetimeCases')]
    public function test_token_name_and_lifetime_come_from_role_and_client(
        string $role,
        ?string $client,
        string $expectedName,
        int $expectedMinutes,
    ): void {
        $employee = Employee::factory()->create([
            'role_id' => $this->role($role)->role_id,
            'site_id' => $this->site()->site_id,
        ]);

        $this->assertSame($expectedName, SessionLifetime::tokenName($employee, $client));

        $expiresAt = SessionLifetime::expiresAt($employee, $client);
        $this->assertInstanceOf(CarbonImmutable::class, $expiresAt);
        $this->assertLessThan(
            60,
            abs(CarbonImmutable::now()->addMinutes($expectedMinutes)->diffInSeconds($expiresAt)),
            "role {$role}, client ".var_export($client, true),
        );
    }

    public static function lifetimeCases(): array
    {
        $web = 720; // 12h staff web
        $portal = 120; // ~2h W3 workers
        $mobile = 30 * 24 * 60; // 30d foreman app

        return [
            // Staff get the web lifetime from the web and from mobile alike —
            // claiming `mobile` buys nobody a longer session.
            'hr from web' => ['hr', 'web', 'web', $web],
            'hr from mobile' => ['hr', 'mobile', 'web', $web],
            'hr with no client' => ['hr', null, 'web', $web],
            'engineer from web' => ['engineer', 'web', 'web', $web],
            'engineer from mobile' => ['engineer', 'mobile', 'web', $web],
            'admin from web' => ['admin', 'web', 'web', $web],
            'admin from mobile' => ['admin', 'mobile', 'web', $web],
            'executive from web' => ['executive', 'web', 'web', $web],
            'executive from mobile' => ['executive', 'mobile', 'web', $web],
            // A foreman on the web is staff; only a foreman on the app gets
            // the 30-day token, named so a revoke can pick it out.
            'foreman from web' => ['foreman', 'web', 'web', $web],
            'foreman with no client' => ['foreman', null, 'web', $web],
            'foreman from mobile' => ['foreman', 'mobile', 'mobile', $mobile],
            // Portal roles get the short lifetime whatever they send.
            'worker from web' => ['worker', 'web', 'portal', $portal],
            'worker from mobile' => ['worker', 'mobile', 'portal', $portal],
            'worker with no client' => ['worker', null, 'portal', $portal],
            'operator from web' => ['operator', 'web', 'portal', $portal],
            'operator from mobile' => ['operator', 'mobile', 'portal', $portal],
        ];
    }
}
