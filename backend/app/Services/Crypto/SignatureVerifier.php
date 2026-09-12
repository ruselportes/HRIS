<?php

namespace App\Services\Crypto;

/**
 * ECDSA P-256 signature verification (Phase 5, TC-03 MitM / forgery).
 *
 * Verifies that the canonical payload was signed by the private key whose
 * public half was registered for this device at binding time. The private key
 * lives in the Android Keystore TEE and is non-exportable, so a valid
 * signature cannot be produced off-device — which is what makes TC-03's
 * second attempt (a signature from a keypair generated outside the TEE) fail
 * even though it is a structurally valid ECDSA signature.
 *
 * Uses PHP's OpenSSL binding directly; no third-party crypto dependency.
 */
class SignatureVerifier
{
    /**
     * @param  array<string, mixed>  $record
     * @param  string  $signatureBase64  base64 of the DER-encoded ECDSA signature
     * @param  string  $publicKeyPem  registered device public key, PEM encoded
     * @return array{valid:bool, reason:string|null}
     */
    public function verify(array $record, string $signatureBase64, string $publicKeyPem): array
    {
        $signature = base64_decode($signatureBase64, true);

        if ($signature === false || $signature === '') {
            return ['valid' => false, 'reason' => 'signature_not_base64'];
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return ['valid' => false, 'reason' => 'public_key_unreadable'];
        }

        $details = openssl_pkey_get_details($publicKey);

        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return ['valid' => false, 'reason' => 'public_key_not_ec'];
        }

        $expectedCurve = config('crypto.signature.curve', 'prime256v1');

        if (($details['ec']['curve_name'] ?? null) !== $expectedCurve) {
            return ['valid' => false, 'reason' => 'public_key_wrong_curve'];
        }

        $payload = AttendancePayload::canonicalize($record);

        $result = openssl_verify(
            $payload,
            $signature,
            $publicKey,
            config('crypto.signature.digest', 'sha256'),
        );

        return match ($result) {
            1 => ['valid' => true, 'reason' => null],
            0 => ['valid' => false, 'reason' => 'signature_mismatch'],
            default => ['valid' => false, 'reason' => 'signature_verify_error'],
        };
    }

    /**
     * Whether a device's reported Android KeyInfo security level is acceptable.
     * An emulator reports SOFTWARE; production should refuse it, but the switch
     * defaults off so local development is not blocked. Flipping
     * HRIS_REQUIRE_HARDWARE_KEYS=true is what makes the hardware-backing claim
     * actually enforced rather than merely documented.
     */
    public function isAcceptableSecurityLevel(?string $securityLevel): bool
    {
        if (! config('crypto.require_hardware_backed_keys', false)) {
            return true;
        }

        return in_array(
            $securityLevel,
            config('crypto.accepted_security_levels', []),
            true,
        );
    }
}
