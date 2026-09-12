<?php

namespace App\Services\Crypto;

use InvalidArgumentException;

/**
 * Canonical serialization of an attendance record — the single definition of
 * *what exactly* gets hashed and signed (Phase 5, supports UC-04).
 *
 * This class and its React Native counterpart (mobile/src/crypto/payload.ts)
 * MUST produce byte-identical output. If they drift, every signature fails
 * and the failure looks like a crypto bug rather than a serialization bug, so
 * both sides are pinned to the shared test vectors in
 * tests/Unit/Crypto/AttendancePayloadTest.php and payload.test.ts. Change the
 * format only by bumping `crypto.payload_version` and updating both sides and
 * both vector sets together.
 *
 * Why not JSON: key ordering, unicode escaping, and float/int rendering all
 * differ between PHP's json_encode and JS's JSON.stringify. A newline-
 * delimited key=value form has none of that ambiguity and stays readable in
 * a debugger, which matters when diagnosing a rejected record.
 *
 * Invariant: no field value may contain a newline. All legitimate values are
 * integers, ISO dates, short lowercase slugs, or hex — enforced below rather
 * than assumed, because a newline injected into device_id would otherwise let
 * an attacker forge field boundaries.
 */
class AttendancePayload
{
    /** Fields in fixed order. Order is part of the format — do not sort. */
    public const FIELDS = [
        'employee_id',
        'crew_id',
        'date',
        'status',
        'time_in',
        'monotonic_timestamp',
        'boot_id',
        'device_id',
        'prev_hash',
    ];

    /**
     * @param  array<string, mixed>  $record
     */
    public static function canonicalize(array $record): string
    {
        $version = config('crypto.payload_version', 'v1');

        $lines = ["version={$version}"];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $record)) {
                throw new InvalidArgumentException(
                    "Attendance payload is missing required field [{$field}]."
                );
            }

            $lines[] = $field.'='.self::normalize($field, $record[$field]);
        }

        return implode("\n", $lines);
    }

    /**
     * Null becomes the empty string — distinct from the string "null" or "0",
     * both of which are legitimate values elsewhere. A genuinely absent
     * time_in (an Absent worker never clocked in) must not collide with a
     * time_in of 0.
     */
    private static function normalize(string $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // Non-integers are refused rather than rendered. PHP and JS disagree on
        // float formatting at the edges — (string) 1e20 is "1.0E+20" here but
        // "100000000000000000000" in JS — so a float would silently produce two
        // different canonical forms and every signature would fail. Mirrors the
        // same guard in mobile/src/crypto/payload.ts.
        if (is_float($value)) {
            if ((float) (int) $value !== $value) {
                throw new InvalidArgumentException(
                    "Attendance payload field [{$field}] must be an integer, got {$value}."
                );
            }

            $value = (int) $value;
        }

        $string = (string) $value;

        if (str_contains($string, "\n") || str_contains($string, "\r")) {
            throw new InvalidArgumentException(
                "Attendance payload field [{$field}] contains a line break, which would "
                .'forge a field boundary in the canonical form.'
            );
        }

        return $string;
    }

    /** SHA-256 digest of the canonical form, as lowercase hex. */
    public static function digest(array $record): string
    {
        return hash('sha256', self::canonicalize($record));
    }
}
