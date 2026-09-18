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
        'captured_at' => 1789200000000,
        'override_type' => null,
        'monotonic_timestamp' => 86400000,
        'boot_id' => 'b7f3c1a2',
        'device_id' => 'dev-mgk3f1-a83bd0e1',
        'prev_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    ];

    public const VECTOR_CANONICAL = "version=v2\n"
        ."employee_id=42\n"
        ."crew_id=7\n"
        ."date=2026-09-12\n"
        ."status=present\n"
        ."time_in=1789200000000\n"
        ."captured_at=1789200000000\n"
        ."override_type=\n"
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

        foreach (AttendancePayload::FIELDS_V2 as $field) {
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
            '576e11103aaa6f32b71fe58d84f34360724a96821fe06186f661c1ad5d190f3c',
            $hmac,
        );
    }

    /**
     * The reason payload v2 exists: in v1 an override could be switched on
     * without touching anything signed. It must now change the digest.
     */
    public function test_override_type_and_captured_at_are_covered_by_the_signature(): void
    {
        $baseline = AttendancePayload::digest(self::VECTOR_RECORD);

        $overridden = array_merge(self::VECTOR_RECORD, ['override_type' => 'shift_credit']);
        $retimed = array_merge(self::VECTOR_RECORD, ['captured_at' => self::VECTOR_RECORD['captured_at'] + 1]);

        $this->assertNotSame($baseline, AttendancePayload::digest($overridden));
        $this->assertNotSame($baseline, AttendancePayload::digest($retimed));
    }

    /**
     * SHARED V3 VECTOR — mobile/src/crypto/__tests__/payload.test.ts and
     * hashChain.test.ts assert the same canonical string, digest and HMAC. A
     * Close-shift time-out: tapped at 16:05 site time (08:05 UTC), crediting
     * 16:00.
     */
    public const V3_RECORD = [
        'payload_version' => 'v3',
        'employee_id' => 42,
        'crew_id' => 7,
        'date' => '2026-09-12',
        'event_type' => 'time_out',
        'status' => 'present',
        'time_in' => null,
        'time_out' => 1789200000000,
        'captured_at' => 1789200300000,
        'override_type' => null,
        'time_out_type' => 'shift_end',
        'monotonic_timestamp' => 86700000,
        'boot_id' => 'b7f3c1a2',
        'device_id' => 'dev-mgk3f1-a83bd0e1',
        'prev_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    ];

    public const V3_CANONICAL = "version=v3\n"
        ."employee_id=42\n"
        ."crew_id=7\n"
        ."date=2026-09-12\n"
        ."event_type=time_out\n"
        ."status=present\n"
        ."time_in=\n"
        ."time_out=1789200000000\n"
        ."captured_at=1789200300000\n"
        ."override_type=\n"
        ."time_out_type=shift_end\n"
        ."monotonic_timestamp=86700000\n"
        ."boot_id=b7f3c1a2\n"
        ."device_id=dev-mgk3f1-a83bd0e1\n"
        .'prev_hash=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_v3_canonical_form_digest_and_hmac_match_the_shared_vector(): void
    {
        $this->assertSame(self::V3_CANONICAL, AttendancePayload::canonicalize(self::V3_RECORD));
        $this->assertSame(
            '2d35f5f2b143db1c3d48e7b8b1d3603540d81bc0ad64ffebca5dfb7659cc1395',
            AttendancePayload::digest(self::V3_RECORD),
        );
        $this->assertSame(
            '63a6c707b3179539fed2dc54984636150f905b224c17af31be2b3de93792b9bb',
            hash_hmac('sha256', AttendancePayload::canonicalize(self::V3_RECORD), base64_decode('c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=')),
        );
    }

    /** A record without a version is v2 — what phones built before v3 send. */
    public function test_an_unversioned_record_is_v2(): void
    {
        $this->assertStringStartsWith("version=v2\n", AttendancePayload::canonicalize(self::VECTOR_RECORD));
    }

    /** The version is signed, so relabelling a v3 event as v2 changes what it verifies against. */
    public function test_the_version_is_part_of_what_is_signed(): void
    {
        $asV2 = array_merge(self::V3_RECORD, ['payload_version' => 'v2']);

        $this->assertNotSame(AttendancePayload::digest(self::V3_RECORD), AttendancePayload::digest($asV2));
    }

    public function test_every_v3_field_is_covered_by_the_signature(): void
    {
        $baseline = AttendancePayload::digest(self::V3_RECORD);

        foreach (['event_type' => 'roll_call', 'time_out' => 1789200000001, 'time_out_type' => 'manual_time'] as $field => $value) {
            $this->assertNotSame($baseline, AttendancePayload::digest(array_merge(self::V3_RECORD, [$field => $value])), $field);
        }
    }

    public function test_a_v3_record_must_carry_the_v3_fields(): void
    {
        $incomplete = self::V3_RECORD;
        unset($incomplete['time_out_type']);

        $this->expectException(InvalidArgumentException::class);
        AttendancePayload::canonicalize($incomplete);
    }

    public function test_an_unknown_version_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AttendancePayload::canonicalize(array_merge(self::VECTOR_RECORD, ['payload_version' => 'v1']));
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
