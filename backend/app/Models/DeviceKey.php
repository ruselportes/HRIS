<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A foreman's bound device (Phase 5, 14th table — see the migration for why it
 * exists outside the original ERD).
 *
 * Holds the two halves of the integrity engine's trust: the ECDSA public key
 * that proves a payload came from this device's TEE, and the HMAC secret that
 * the hash chain is keyed on. The HMAC secret is symmetric and therefore only
 * evidence of local tampering; the ECDSA key is what provides origin proof.
 */
class DeviceKey extends Model
{
    use HasFactory;

    protected $primaryKey = 'device_key_id';

    protected $fillable = [
        'employee_id',
        'device_id',
        'public_key',
        'hmac_key',
        'security_level',
        'last_chain_hash',
        // Clock history for cross-batch continuity. Omitting these from
        // fillable made update() drop them silently — mass-assignment
        // protection fails quietly, so the rollback check passed a batch it
        // should have rejected.
        'last_monotonic_timestamp',
        'last_time_in',
        'last_boot_id',
        'bound_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest. Reading this attribute decrypts with APP_KEY;
            // the column is never queried directly for that reason.
            'hmac_key' => 'encrypted',
            'bound_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Never serialize the shared secret. It is returned exactly once, by the
     * binding endpoint, and never again — an accidental `return $deviceKey`
     * anywhere else must not leak it.
     */
    protected $hidden = [
        'hmac_key',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Raw HMAC key bytes, as the chain verifier expects them. */
    public function hmacKeyBytes(): string
    {
        return base64_decode($this->hmac_key, true) ?: '';
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
