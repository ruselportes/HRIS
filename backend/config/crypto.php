<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attendance Integrity Engine (Phase 5)
    |--------------------------------------------------------------------------
    |
    | Tunables for the 4-layer integrity engine. Kept in config rather than as
    | constants so a wage-order-style silent staleness problem can't happen
    | here either: these are operational thresholds, not physical facts.
    |
    */

    'payload_version' => 'v1',

    /*
     | How far the device's wall clock may disagree with its own monotonic
     | counter between two consecutive records before the record is treated as
     | clock tampering (TC-01).
     |
     | Legitimate causes of small disagreement: NTP correction, DST/timezone
     | changes, sub-second rounding. These are seconds at most. A rollback
     | attack is minutes-to-hours, so a generous tolerance still catches it
     | while never flagging honest drift.
     */
    'clock_skew_tolerance_seconds' => (int) env('HRIS_CLOCK_SKEW_TOLERANCE', 120),

    /*
     | ECDSA verification. P-256 (prime256v1 in OpenSSL's naming) per the SPMP
     | fixed stack. Signatures arrive base64-encoded, DER-wrapped, over a
     | SHA-256 digest of the canonical payload.
     */
    'signature' => [
        'curve' => 'prime256v1',
        'digest' => 'sha256',
    ],

    /*
     | Android KeyInfo security levels we accept as genuinely hardware-backed.
     | An emulator reports SOFTWARE, so it is listed but must be enabled
     | explicitly — that switch is what keeps a dev-convenience default from
     | silently becoming the production posture.
     */
    'require_hardware_backed_keys' => (bool) env('HRIS_REQUIRE_HARDWARE_KEYS', false),

    'accepted_security_levels' => ['STRONGBOX', 'TRUSTED_ENVIRONMENT'],

];
