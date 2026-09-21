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

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRING_SOON = 'expiring_soon';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NO_EXPIRY = 'no_expiry';

    /**
     * One status per certificate: valid, expiring soon (within EXPIRING_DAYS),
     * expired, or no expiry date. Unparseable dates read as no expiry — they
     * carry no usable deadline, so neither screen may count them as dated.
     *
     * @return array<int, array{certificate: mixed, status: string}>
     */
    public static function each(?array $certs, ?Carbon $today = null): array
    {
        $today = $today ?? Carbon::today();
        $limit = $today->copy()->addDays(self::EXPIRING_DAYS);
        // Deadlines are calendar days in the viewer's timezone (Manila for
        // both screens), not UTC instants: parsing in the default timezone
        // would shift a due-in-14-days date 8 hours past the limit and read
        // it as valid.
        $timezone = $today->getTimezone();

        $marked = [];

        foreach ($certs ?? [] as $cert) {
            $expiresAt = is_array($cert) ? ($cert['expires_at'] ?? null) : null;

            if (! $expiresAt) {
                $marked[] = ['certificate' => $cert, 'status' => self::STATUS_NO_EXPIRY];

                continue;
            }

            try {
                $expiry = Carbon::parse($expiresAt, $timezone)->startOfDay();
            } catch (\Exception) {
                $marked[] = ['certificate' => $cert, 'status' => self::STATUS_NO_EXPIRY];

                continue;
            }

            $marked[] = [
                'certificate' => $cert,
                'status' => $expiry->lt($today)
                    ? self::STATUS_EXPIRED
                    : ($expiry->lte($limit) ? self::STATUS_EXPIRING_SOON : self::STATUS_VALID),
            ];
        }

        return $marked;
    }

    public static function for(?array $certs, ?Carbon $today = null): array
    {
        $dated = array_filter(
            self::each($certs, $today),
            fn (array $m) => $m['status'] !== self::STATUS_NO_EXPIRY,
        );

        $expired = $expiring = 0;

        foreach ($dated as $m) {
            if ($m['status'] === self::STATUS_EXPIRED) {
                $expired++;
            } elseif ($m['status'] === self::STATUS_EXPIRING_SOON) {
                $expiring++;
            }
        }

        $total = count($dated);

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
