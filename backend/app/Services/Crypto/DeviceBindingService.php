<?php

namespace App\Services\Crypto;

use App\Models\AuditLog;
use App\Models\DeviceKey;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Device binding (Phase 5) — the trust anchor for the whole integrity engine.
 *
 * Binding registers the device's TEE-generated ECDSA public key and issues the
 * per-device HMAC secret that its hash chain is keyed on. Everything the
 * verifiers do afterwards is relative to what was established here.
 *
 * HONEST LIMITATION, worth stating rather than leaving implied: binding is
 * authenticated by the foreman's ordinary login token, so the crypto is only
 * as strong as that password. Someone with stolen credentials can bind their
 * own device and then submit records that verify perfectly — no amount of
 * TEE signing prevents that, because the attacker is using a real TEE. What
 * the engine does prevent is tampering *after* capture, and forgery by anyone
 * who is not holding a bound device. The mitigation for the bootstrap case is
 * detection, not prevention: every bind and rebind writes an audit entry, so a
 * device appearing where it should not is visible.
 */
class DeviceBindingService
{
    public function __construct(private SignatureVerifier $signatureVerifier) {}

    /**
     * Bind (or rebind) a device to a foreman.
     *
     * @return array{device_key: DeviceKey, hmac_key_base64: string, rebound: bool}
     */
    public function bind(
        Employee $employee,
        string $deviceId,
        string $publicKeyPem,
        ?string $securityLevel,
    ): array {
        // Fresh secret on every bind. A rebind must not inherit the old key,
        // or a device that was revoked for being compromised could keep
        // producing chain entries that still verify.
        $hmacKeyBase64 = base64_encode(random_bytes(32));

        return DB::transaction(function () use ($employee, $deviceId, $publicKeyPem, $securityLevel, $hmacKeyBase64) {
            $existing = DeviceKey::where('device_id', $deviceId)->first();
            $previousOwnerId = $existing?->employee_id;

            $deviceKey = DeviceKey::updateOrCreate(
                ['device_id' => $deviceId],
                [
                    'employee_id' => $employee->employee_id,
                    'public_key' => $publicKeyPem,
                    'hmac_key' => $hmacKeyBase64,
                    'security_level' => $securityLevel,
                    // New keypair means a new chain: the old chain was keyed on
                    // a secret that no longer exists, so no record from it can
                    // legitimately continue. Resetting to null is what lets the
                    // device's next record be accepted as a fresh baseline.
                    'last_chain_hash' => null,
                    'bound_at' => now(),
                    'revoked_at' => null,
                ],
            );

            $rebound = $existing !== null;

            AuditLog::create([
                'actor_id' => $employee->employee_id,
                'action_type' => $rebound ? 'DEVICE_REBOUND' : 'DEVICE_BOUND',
                'description' => $this->describeBinding(
                    $deviceId,
                    $securityLevel,
                    $rebound,
                    $previousOwnerId,
                    $employee->employee_id,
                ),
                'timestamp' => now(),
            ]);

            return [
                'device_key' => $deviceKey,
                'hmac_key_base64' => $hmacKeyBase64,
                'rebound' => $rebound,
            ];
        });
    }

    private function describeBinding(
        string $deviceId,
        ?string $securityLevel,
        bool $rebound,
        ?int $previousOwnerId,
        int $newOwnerId,
    ): string {
        $level = $securityLevel ?? 'unreported';
        $description = "Device {$deviceId} bound with security level {$level}.";

        // A device moving between foremen is the case most worth being able to
        // find later — a handover if legitimate, an account compromise if not.
        if ($rebound && $previousOwnerId !== null && $previousOwnerId !== $newOwnerId) {
            $description .= " Reassigned from employee {$previousOwnerId} to employee {$newOwnerId}.";
        } elseif ($rebound) {
            $description .= ' Rebound to the same employee; previous key and chain discarded.';
        }

        return $description;
    }

    /** Whether the submitted key is structurally acceptable to bind. */
    public function validatePublicKey(string $publicKeyPem): array
    {
        return $this->signatureVerifier->inspectPublicKey($publicKeyPem);
    }

    public function revoke(DeviceKey $deviceKey, Employee $actor, string $reason): void
    {
        DB::transaction(function () use ($deviceKey, $actor, $reason) {
            $deviceKey->update(['revoked_at' => now()]);

            AuditLog::create([
                'actor_id' => $actor->employee_id,
                'action_type' => 'DEVICE_REVOKED',
                'description' => "Device {$deviceKey->device_id} revoked: {$reason}",
                'timestamp' => now(),
            ]);
        });
    }
}
