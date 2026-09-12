<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\AttendancePayload;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * SHARED TEST VECTOR — mobile/src/crypto/__tests__/payload.test.ts asserts the
 * identical canonical string and digest. If you change the format here without
 * changing it there (and bumping crypto.payload_version), every signature the
 * app produces will fail verification.
 */
class AttendancePayloadTest extends TestCase
{
    /** The one record both implementations are pinned to. */
    public const VECTOR_RECORD = [
        'employee_id' => 42,
        'crew_id' => 7,
        'date' => '2026-09-12',
        'status' => 'present',
        'time_in' => 1789200000000,
        'monotonic_timestamp' => 86400000,
        'boot_id' => 'b7f3c1a2',
        'device_id' => 'dev-mgk3f1-a83bd0e1',
        'prev_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    ];

    public const VECTOR_CANONICAL = "version=v1\n"
        ."employee_id=42\n"
        ."crew_id=7\n"
        ."date=2026-09-12\n"
        ."status=present\n"
        ."time_in=1789200000000\n"
        ."monotonic_timestamp=86400000\n"
        ."boot_id=b7f3c1a2\n"
        ."device_id=dev-mgk3f1-a83bd0e1\n"
        .'prev_hash=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_canonical_form_matches_the_shared_vector(): void
    {
        $this->assertSame(
            self::VECTOR_CANONICAL,
            AttendancePayload::canonicalize(self::VECTOR_RECORD),
        );
    }

    public function test_digest_matches_the_shared_vector(): void
    {
        $this->assertSame(
            hash('sha256', self::VECTOR_CANONICAL),
            AttendancePayload::digest(self::VECTOR_RECORD),
        );
    }

    public function test_field_order_is_fixed_regardless_of_input_key_order(): void
    {
        $shuffled = array_reverse(self::VECTOR_RECORD, true);

        $this->assertSame(
            AttendancePayload::canonicalize(self::VECTOR_RECORD),
            AttendancePayload::canonicalize($shuffled),
        );
    }

    public function test_null_time_in_is_distinct_from_zero(): void
    {
        $absent = array_merge(self::VECTOR_RECORD, ['time_in' => null, 'status' => 'absent']);
        $atEpoch = array_merge(self::VECTOR_RECORD, ['time_in' => 0, 'status' => 'absent']);

        $this->assertNotSame(
            AttendancePayload::canonicalize($absent),
            AttendancePayload::canonicalize($atEpoch),
        );
        $this->assertStringContainsString("time_in=\n", AttendancePayload::canonicalize($absent));
        $this->assertStringContainsString("time_in=0\n", AttendancePayload::canonicalize($atEpoch));
    }

    public function test_changing_any_single_field_changes_the_digest(): void
    {
        $baseline = AttendancePayload::digest(self::VECTOR_RECORD);

        foreach (AttendancePayload::FIELDS as $field) {
            $mutated = self::VECTOR_RECORD;
            $mutated[$field] = is_int($mutated[$field]) ? $mutated[$field] + 1 : $mutated[$field].'x';

            $this->assertNotSame(
                $baseline,
                AttendancePayload::digest($mutated),
                "Mutating [{$field}] did not change the digest — it is not covered by the signature."
            );
        }
    }

    public function test_missing_field_is_rejected_rather_than_silently_defaulted(): void
    {
        $incomplete = self::VECTOR_RECORD;
        unset($incomplete['monotonic_timestamp']);

        $this->expectException(InvalidArgumentException::class);
        AttendancePayload::canonicalize($incomplete);
    }

    public function test_non_integer_float_is_rejected_rather_than_formatted(): void
    {
        // Mirrors the same guard in mobile/src/crypto/payload.ts — PHP and JS
        // format floats differently at the edges, which would silently produce
        // two different canonical forms.
        $floaty = array_merge(self::VECTOR_RECORD, ['monotonic_timestamp' => 86400000.5]);

        $this->expectException(InvalidArgumentException::class);
        AttendancePayload::canonicalize($floaty);
    }

    /**
     * SHARED HMAC VECTOR — mobile/src/crypto/__tests__/hashChain.test.ts asserts
     * this same value. Pinned from both sides because the key convention (the
     * server issues base64; both sides HMAC the DECODED bytes) is exactly where
     * a silent divergence would produce valid-looking but unverifiable HMACs.
     */
    public function test_hmac_over_the_vector_matches_the_mobile_side(): void
    {
        $keyBase64 = 'c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=';

        $hmac = hash_hmac(
            'sha256',
            AttendancePayload::canonicalize(self::VECTOR_RECORD),
            base64_decode($keyBase64),
        );

        $this->assertSame(
            'a82f7ef2c38cf3e92cb99da6aa8016701bc33444d25004f3cf0d2133517c5529',
            $hmac,
        );
    }

    public function test_line_break_in_a_field_is_rejected(): void
    {
        // Without this guard, a device_id of "x\nprev_hash=deadbeef" could
        // forge a field boundary and restate a later field.
        $injected = array_merge(self::VECTOR_RECORD, [
            'device_id' => "x\nprev_hash=0000000000000000000000000000000000000000000000000000000000000000",
        ]);

        $this->expectException(InvalidArgumentException::class);
        AttendancePayload::canonicalize($injected);
    }
}
