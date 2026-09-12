<?php

namespace Database\Factories;

use App\Models\DeviceKey;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceKey>
 */
class DeviceKeyFactory extends Factory
{
    /**
     * A throwaway P-256 public key, fixed rather than generated: PHP's
     * openssl_pkey_new() needs an openssl.cnf this project's Windows/XAMPP PHP
     * cannot locate, so runtime keygen makes the suite machine-dependent. See
     * tests/Unit/Crypto/SignatureVerifierTest for the same reasoning.
     */
    public const TEST_PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\n"
        ."MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEZ/qRNvjsKs4egbV0nnqxBsZUy99F\n"
        ."pMvaxz2DLkPSBWBVJ3o4bWyvrgs25cn1pTsgeydUqxqWHRqMhz5o+SY3HQ==\n"
        ."-----END PUBLIC KEY-----\n";

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'device_id' => 'dev-'.$this->faker->unique()->bothify('??????-########'),
            'public_key' => self::TEST_PUBLIC_KEY_PEM,
            'hmac_key' => base64_encode(random_bytes(32)),
            'security_level' => 'TRUSTED_ENVIRONMENT',
            'last_chain_hash' => null,
            'bound_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function softwareBacked(): static
    {
        return $this->state(fn () => ['security_level' => 'SOFTWARE']);
    }
}
