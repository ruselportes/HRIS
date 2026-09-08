<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Computes certification status from the Employee.certification JSON array
 * (shape: name/issuer/certificate_no/issued_at/expires_at — same keys the web
 * add-form writes). Phase 2 deliberately deferred cert filtering; Phase 3's
 * Crew Builder pool needed a status per worker, so we parse the JSON in PHP
 * here (no SQL JSON extract, no new table). If a later module needs durable
 * cert queries/reporting, normalize into employee_certifications then — not
 * silently as part of Phase 3.
 */
class CertificationStatus
{
    public const EXPIRING_DAYS = 14;

    public static function for(?array $certs, ?Carbon $today = null): array
    {
        $today = $today ?? Carbon::today();

        $total = $expired = $expiring = 0;

        foreach ($certs ?? [] as $cert) {
            $expiresAt = is_array($cert) ? ($cert['expires_at'] ?? null) : null;
            if (! $expiresAt) {
                continue;
            }

            try {
                $expiry = Carbon::parse($expiresAt)->startOfDay();
            } catch (\Exception) {
                continue;
            }

            $total++;

            if ($expiry->lt($today)) {
                $expired++;
            } elseif ($expiry->lte($today->copy()->addDays(self::EXPIRING_DAYS))) {
                $expiring++;
            }
        }

        $status = $total === 0
            ? 'none'
            : ($expired > 0 ? 'expired' : ($expiring > 0 ? 'expiring_soon' : 'valid'));

        return [
            'status' => $status,
            'total' => $total,
            'expired' => $expired,
            'expiring' => $expiring,
        ];
    }
}
