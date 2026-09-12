<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\HashChainVerifier;
use Tests\TestCase;

/**
 * Covers STD TC-02 (local database tampering): editing one row must invalidate
 * that row's own HMAC and break the linkage to every row after it, while rows
 * before it stay acceptable.
 */
class HashChainVerifierTest extends TestCase
{
    private HashChainVerifier $verifier;

    private string $key = 'per-device-hmac-secret-from-binding';

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new HashChainVerifier;
    }

    /**
     * Build a well-formed chain of n records, each correctly linked to the one
     * before it — i.e. what an untampered device produces.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildChain(int $count): array
    {
        $records = [];
        $prevHash = null;

        for ($i = 0; $i < $count; $i++) {
            $record = [
                'employee_id' => 100 + $i,
                'crew_id' => 7,
                'date' => '2026-09-12',
                'status' => 'present',
                'time_in' => 1789200000000 + ($i * 60_000),
                'monotonic_timestamp' => 86400000 + ($i * 60_000),
                'boot_id' => 'b7f3c1a2',
                'device_id' => 'dev-mgk3f1-a83bd0e1',
                'prev_hash' => $prevHash,
            ];

            $record['hmac_hash'] = $this->verifier->computeHmac($record, $this->key);
            $prevHash = $record['hmac_hash'];
            $records[] = $record;
        }

        return $records;
    }

    public function test_untampered_chain_verifies_end_to_end(): void
    {
        $results = $this->verifier->verifyChain($this->buildChain(3), $this->key);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertTrue($result['valid'], "Record {$result['index']} should verify.");
            $this->assertNull($result['reason']);
        }
    }

    /** TC-02: alter row 2's time_in without recomputing any hashes. */
    public function test_editing_the_middle_row_rejects_it_and_everything_after(): void
    {
        $records = $this->buildChain(3);

        // 08:15 -> 07:00, the exact manipulation TC-02 describes.
        $records[1]['time_in'] -= 75 * 60 * 1000;

        $results = $this->verifier->verifyChain($records, $this->key);

        $this->assertTrue($results[0]['valid'], 'Row 1 precedes the edit and must still be accepted.');

        $this->assertFalse($results[1]['valid']);
        $this->assertSame('hmac_mismatch', $results[1]['reason']);

        $this->assertFalse($results[2]['valid']);
        $this->assertSame(
            'chain_broken_upstream',
            $results[2]['reason'],
            'Row 3 self-checks fine but descends from a rejected row, so it cannot be trusted.'
        );
    }

    public function test_recomputing_the_edited_rows_hmac_still_fails_via_broken_linkage(): void
    {
        // A more capable attacker edits row 2 *and* fixes row 2's own HMAC.
        // Row 3's prev_hash still points at the old row-2 hash, so the chain
        // breaks one step later instead of being silently accepted.
        $records = $this->buildChain(3);
        $records[1]['time_in'] -= 75 * 60 * 1000;
        $records[1]['hmac_hash'] = $this->verifier->computeHmac($records[1], $this->key);

        $results = $this->verifier->verifyChain($records, $this->key);

        $this->assertTrue($results[0]['valid']);
        $this->assertTrue($results[1]['valid'], 'Row 2 now self-checks — that is expected.');
        $this->assertFalse($results[2]['valid']);
        $this->assertSame('prev_hash_mismatch', $results[2]['reason']);
    }

    public function test_wrong_hmac_key_rejects_the_whole_chain(): void
    {
        $results = $this->verifier->verifyChain($this->buildChain(2), 'not-the-right-key');

        $this->assertFalse($results[0]['valid']);
        $this->assertSame('hmac_mismatch', $results[0]['reason']);
    }

    public function test_chain_must_continue_from_the_last_known_server_hash(): void
    {
        // Guards against a device replaying a fresh chain that ignores history.
        $records = $this->buildChain(2);

        $results = $this->verifier->verifyChain($records, $this->key, 'a-previous-hash-the-server-holds');

        $this->assertFalse($results[0]['valid']);
        $this->assertSame('prev_hash_mismatch', $results[0]['reason']);
    }

    public function test_reordering_records_is_detected(): void
    {
        $records = $this->buildChain(3);
        [$records[1], $records[2]] = [$records[2], $records[1]];

        $results = $this->verifier->verifyChain($records, $this->key);

        $this->assertTrue($results[0]['valid']);
        $this->assertFalse($results[1]['valid']);
        $this->assertSame('prev_hash_mismatch', $results[1]['reason']);
    }
}
