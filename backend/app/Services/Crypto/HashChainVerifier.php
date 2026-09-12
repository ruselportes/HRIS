<?php

namespace App\Services\Crypto;

/**
 * HMAC-SHA256 hash chain verification (Phase 5, TC-02 local DB tampering).
 *
 * Each record stores hmac_hash = HMAC(key, canonical_payload) where the
 * payload itself embeds prev_hash. So a record's hash covers both its own
 * contents and its position in the chain: editing row 2 invalidates row 2's
 * own HMAC *and* breaks row 3's linkage, which is the property TC-02 asserts.
 *
 * The HMAC key is the per-device secret issued at binding (device_keys), so
 * the server can recompute. That alone is not proof of origin — anyone
 * holding the shared key could forge it — which is why ECDSA signing over the
 * same payload sits on top (see SignatureVerifier).
 *
 * Operates on plain arrays, not Eloquent models, so it is unit-testable with
 * no database.
 */
class HashChainVerifier
{
    /**
     * Verify a batch of records presented in chain order.
     *
     * Returns a per-record result rather than throwing, because TC-02 requires
     * partial acceptance: row 1 is accepted while rows 2 and 3 are rejected,
     * and the response must identify where the chain broke.
     *
     * @param  array<int, array<string, mixed>>  $records  in chain order
     * @param  string  $hmacKey  raw per-device secret
     * @param  string|null  $expectedFirstPrevHash  last known good hash for this
     *                                              device, or null if this is the device's first ever batch
     * @return array<int, array{index:int, valid:bool, reason:string|null, expected_hmac:string}>
     */
    public function verifyChain(
        array $records,
        string $hmacKey,
        ?string $expectedFirstPrevHash = null,
    ): array {
        $results = [];
        $previousHash = $expectedFirstPrevHash;
        $chainBroken = false;

        foreach (array_values($records) as $index => $record) {
            $expectedHmac = $this->computeHmac($record, $hmacKey);

            // Once the chain is broken, every later record is untrustworthy
            // even if its own HMAC self-checks: its prev_hash points into a
            // history the server never accepted.
            if ($chainBroken) {
                $results[] = [
                    'index' => $index,
                    'valid' => false,
                    'reason' => 'chain_broken_upstream',
                    'expected_hmac' => $expectedHmac,
                ];

                continue;
            }

            $linkageOk = ($record['prev_hash'] ?? null) === $previousHash;

            if (! $linkageOk) {
                $chainBroken = true;
                $results[] = [
                    'index' => $index,
                    'valid' => false,
                    'reason' => 'prev_hash_mismatch',
                    'expected_hmac' => $expectedHmac,
                ];

                continue;
            }

            // hash_equals, not ===, to avoid leaking position of first
            // differing byte through timing.
            $storedHmac = (string) ($record['hmac_hash'] ?? '');

            if (! hash_equals($expectedHmac, $storedHmac)) {
                $chainBroken = true;
                $results[] = [
                    'index' => $index,
                    'valid' => false,
                    'reason' => 'hmac_mismatch',
                    'expected_hmac' => $expectedHmac,
                ];

                continue;
            }

            $results[] = [
                'index' => $index,
                'valid' => true,
                'reason' => null,
                'expected_hmac' => $expectedHmac,
            ];

            $previousHash = $expectedHmac;
        }

        return $results;
    }

    public function computeHmac(array $record, string $hmacKey): string
    {
        return hash_hmac('sha256', AttendancePayload::canonicalize($record), $hmacKey);
    }
}
