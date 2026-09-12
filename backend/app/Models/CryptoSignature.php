<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_crypto_signature (signature_id, attendance_id FK unique,
 * hmac_hash, prev_hash, ecdsa_signature, verified).
 *
 * 1:1 with an attendance row, per the ERD's unique constraint. The device
 * sends an append-only event log, so when several events collapse into one
 * attendance row this holds the LAST ACCEPTED event's signature. Superseded
 * events are still verified — the chain cannot be checked otherwise — but
 * their individual signatures are not retained; the verification outcome goes
 * to audit_log instead. Retaining every event's signature would mean breaking
 * the ERD's 1:1 constraint, which is a schema decision for the team rather
 * than something to slip in.
 */
class CryptoSignature extends Model
{
    use HasFactory;

    protected $primaryKey = 'signature_id';

    protected $fillable = [
        'attendance_id',
        'hmac_hash',
        'prev_hash',
        'ecdsa_signature',
        'verified',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id', 'attendance_id');
    }
}
