<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\AttendancePayload;
use App\Services\Crypto\SignatureVerifier;
use Tests\TestCase;

/**
 * Covers STD TC-03 (MitM / signature forgery). Both of TC-03's attempts are
 * exercised: a payload altered in transit with the signature left intact, and
 * a signature produced by a keypair that was never registered for the device.
 *
 * ============================================================================
 * THESE ARE THROWAWAY TEST KEYS. Generated for this test file alone, never
 * used by any device, environment, or deployment. Do not copy them anywhere.
 * ============================================================================
 *
 * They are committed rather than generated at runtime because
 * openssl_pkey_new() needs an openssl.cnf that this project's Windows/XAMPP
 * PHP cannot locate ("configuration file routines::no such file"), which made
 * the suite pass or fail depending on whose machine ran it. Note that only key
 * *generation* needs that config — openssl_verify() and
 * openssl_pkey_get_public(), which is all the production code calls, do not.
 * So this is a test-portability fix, not a workaround for a product defect.
 */
class SignatureVerifierTest extends TestCase
{
    /** The device's registered keypair — the "TEE" key in TC-03 terms. */
    private const DEVICE_PRIVATE_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgoRXcZuXCr+JcSWBP
        coidw4Idh78Liyfxw6E+5beiGXKhRANCAARn+pE2+Owqzh6BtXSeerEGxlTL30Wk
        y9rHPYMuQ9IFYFUnejhtbK+uCzblyfWlOyB7J1SrGpYdGoyHPmj5Jjcd
        -----END PRIVATE KEY-----
        PEM;

    private const DEVICE_PUBLIC_PEM = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEZ/qRNvjsKs4egbV0nnqxBsZUy99F
        pMvaxz2DLkPSBWBVJ3o4bWyvrgs25cn1pTsgeydUqxqWHRqMhz5o+SY3HQ==
        -----END PUBLIC KEY-----
        PEM;

    /** An unregistered P-256 key — TC-03's "generated outside the TEE" case. */
    private const ATTACKER_PRIVATE_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg0co8kBslSE2LszvX
        7ElKgfZl0fSvRj8M6ky8sohyDhKhRANCAAQuPfazI66/JV+8J/Gj4WzW/dQEIjS4
        LooHIQHuVAwJoe/no1j7IA+cfS1h+lKrY+CA87TBZLd9sg/XdFTwadHL
        -----END PRIVATE KEY-----
        PEM;

    /** Wrong curve (P-384) — the SPMP pins P-256. */
    private const P384_PRIVATE_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIG2AgEAMBAGByqGSM49AgEGBSuBBAAiBIGeMIGbAgEBBDAa3CXVJUMwWcrRtdmt
        yYFWA1t6/IaAdUUMLX7Od+LZQM+xIYthhEsw+1FsZ+yMiOihZANiAAQszQGE3OAI
        Zu3IgXi2+IcMLrAUmj/22DUG7A/56ReZ/imuGXb1oDxIySESs0u1YmePHyH3M4Dx
        qkRT/sixZLXU4/MLvLLuPaLft7TQjqN3yc20INEdLxfThr/T/yyekN8=
        -----END PRIVATE KEY-----
        PEM;

    private const P384_PUBLIC_PEM = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAELM0BhNzgCGbtyIF4tviHDC6wFJo/9tg1
        BuwP+ekXmf4prhl29aA8SMkhErNLtWJnjx8h9zOA8apEU/7IsWS11OPzC7yy7j2i
        37e00I6jd8nNtCDRHS8X04a/0/8snpDf
        -----END PUBLIC KEY-----
        PEM;

    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new SignatureVerifier;
    }

    /** Heredoc indentation has to be stripped before OpenSSL will parse a PEM. */
    private function pem(string $indented): string
    {
        return implode("\n", array_map('trim', explode("\n", trim($indented))))."\n";
    }

    private function sign(array $record, string $privateKeyPem): string
    {
        $ok = openssl_sign(
            AttendancePayload::canonicalize($record),
            $signature,
            $this->pem($privateKeyPem),
            'sha256',
        );

        $this->assertTrue($ok, 'Test fixture key failed to sign — the fixture PEM is malformed.');

        return base64_encode($signature);
    }

    private function record(): array
    {
        return AttendancePayloadTest::VECTOR_RECORD;
    }

    public function test_signature_from_the_registered_key_verifies(): void
    {
        $signature = $this->sign($this->record(), self::DEVICE_PRIVATE_PEM);

        $result = $this->verifier->verify(
            $this->record(),
            $signature,
            $this->pem(self::DEVICE_PUBLIC_PEM),
        );

        $this->assertTrue($result['valid'], (string) $result['reason']);
    }

    /** TC-03 attempt 1: field altered in transit, signature left untouched. */
    public function test_payload_altered_in_transit_fails_verification(): void
    {
        $signature = $this->sign($this->record(), self::DEVICE_PRIVATE_PEM);

        $tampered = array_merge($this->record(), ['employee_id' => 43]);

        $result = $this->verifier->verify($tampered, $signature, $this->pem(self::DEVICE_PUBLIC_PEM));

        $this->assertFalse($result['valid']);
        $this->assertSame('signature_mismatch', $result['reason']);
    }

    public function test_rolling_time_in_back_in_transit_fails_verification(): void
    {
        $signature = $this->sign($this->record(), self::DEVICE_PRIVATE_PEM);

        $tampered = array_merge($this->record(), ['time_in' => 1789200000000 - 7_200_000]);

        $result = $this->verifier->verify($tampered, $signature, $this->pem(self::DEVICE_PUBLIC_PEM));

        $this->assertFalse($result['valid']);
        $this->assertSame('signature_mismatch', $result['reason']);
    }

    /** TC-03 attempt 2: structurally valid signature from an unregistered key. */
    public function test_signature_from_a_key_generated_outside_the_tee_fails(): void
    {
        $forged = $this->sign($this->record(), self::ATTACKER_PRIVATE_PEM);

        $result = $this->verifier->verify($this->record(), $forged, $this->pem(self::DEVICE_PUBLIC_PEM));

        $this->assertFalse($result['valid']);
        $this->assertSame('signature_mismatch', $result['reason']);
    }

    public function test_non_base64_signature_is_rejected_cleanly(): void
    {
        $result = $this->verifier->verify(
            $this->record(),
            '!!!not base64!!!',
            $this->pem(self::DEVICE_PUBLIC_PEM),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('signature_not_base64', $result['reason']);
    }

    public function test_unreadable_public_key_is_rejected_cleanly(): void
    {
        $signature = $this->sign($this->record(), self::DEVICE_PRIVATE_PEM);

        $result = $this->verifier->verify($this->record(), $signature, 'not a pem');

        $this->assertFalse($result['valid']);
        $this->assertSame('public_key_unreadable', $result['reason']);
    }

    public function test_wrong_curve_is_rejected_even_though_the_signature_itself_is_valid(): void
    {
        // P-384 verification would succeed on its own terms; the curve check is
        // what keeps the deployed algorithm pinned to what the SPMP specifies.
        $signature = $this->sign($this->record(), self::P384_PRIVATE_PEM);

        $result = $this->verifier->verify($this->record(), $signature, $this->pem(self::P384_PUBLIC_PEM));

        $this->assertFalse($result['valid']);
        $this->assertSame('public_key_wrong_curve', $result['reason']);
    }

    public function test_software_backed_keys_are_refused_when_hardware_is_required(): void
    {
        config(['crypto.require_hardware_backed_keys' => true]);

        $this->assertFalse($this->verifier->isAcceptableSecurityLevel('SOFTWARE'));
        $this->assertFalse($this->verifier->isAcceptableSecurityLevel(null));
        $this->assertTrue($this->verifier->isAcceptableSecurityLevel('STRONGBOX'));
        $this->assertTrue($this->verifier->isAcceptableSecurityLevel('TRUSTED_ENVIRONMENT'));
    }

    public function test_software_backed_keys_are_tolerated_when_hardware_is_not_required(): void
    {
        // The emulator path — development must not be blocked, but this is the
        // switch that has to be flipped for the hardware claim to hold.
        config(['crypto.require_hardware_backed_keys' => false]);

        $this->assertTrue($this->verifier->isAcceptableSecurityLevel('SOFTWARE'));
    }
}
