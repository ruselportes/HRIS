<?php

namespace App\Support;

use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Who gets which sign-in token. The expiry is decided server-side from the
 * account's role and the client hint — a request parameter alone must never
 * buy a longer session, since anyone can send `client: mobile`.
 *
 * - Portal roles (worker/operator, Add-on B W3) get the short portal
 *   lifetime whatever they send: shared phones and computers.
 * - A foreman on the mobile app gets 30 days. The token is only checked when
 *   the phone reaches the server; offline, the app opens from the saved
 *   sign-in whatever the token's age, and an expired token just means signing
 *   in again once there is signal. Unsynced records and the device binding
 *   survive a new sign-in by the same foreman. Finite is free, and it bounds
 *   a lost phone's session (revoke deletes its `mobile` tokens outright).
 * - Everyone else — staff on the web, staff claiming mobile, portal roles on
 *   the web — gets the 12-hour web lifetime.
 *
 * Token names follow the granted tier (`web` / `mobile` / `portal`), never
 * the claimed client, so revocation can pick out a phone's tokens by name.
 * Lifetimes are plain config values (config/sanctum.php `session_lifetimes`),
 * not env vars, so no compose `x-api-env` change is needed to deploy them.
 */
class SessionLifetime
{
    public const CLIENT_WEB = 'web';

    public const CLIENT_MOBILE = 'mobile';

    public const CLIENT_PORTAL = 'portal';

    public static function tokenName(Employee $employee, ?string $client): string
    {
        if ($client === self::CLIENT_MOBILE && $employee->role?->slug === 'foreman') {
            return self::CLIENT_MOBILE;
        }

        if ($employee->role?->isPortalRole()) {
            return self::CLIENT_PORTAL;
        }

        return self::CLIENT_WEB;
    }

    public static function expiresAt(Employee $employee, ?string $client): ?CarbonImmutable
    {
        if ($employee->role?->isPortalRole()) {
            return CarbonImmutable::now()->addMinutes((int) config('sanctum.session_lifetimes.portal_minutes'));
        }

        if ($employee->role?->slug === 'foreman' && $client === self::CLIENT_MOBILE) {
            return CarbonImmutable::now()->addDays((int) config('sanctum.session_lifetimes.mobile_days'));
        }

        return CarbonImmutable::now()->addMinutes((int) config('sanctum.session_lifetimes.web_minutes'));
    }
}
