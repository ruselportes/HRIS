<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 device registry — a documented 14th table, NOT in the original
     * 13-table ERD.
     *
     * Why it has to exist: STD TC-03's setup requires the device keypair's
     * public half to be "registered on the server", and TC-02 requires the
     * server to recompute each record's HMAC, which means the HMAC secret must
     * be server-known too. The ERD models neither — tbl_attendance_sync_queue
     * carries a bare `device_id` string with nothing behind it, and there is no
     * column anywhere that could hold a public key. So unlike the
     * `certifications` table proposed in Phase 3 (rejected, because
     * Employee.certification already existed to hold that data), this is a real
     * gap rather than a redundancy.
     *
     * Needs reflecting in the SDD §3.1 data dictionary and HRIS_ERD.drawio
     * before submission — that is Efren's ownership, not something this
     * migration can do on its own.
     */
    public function up(): void
    {
        Schema::create('device_keys', function (Blueprint $table) {
            $table->bigIncrements('device_key_id');

            // The foreman the device is bound to. Cascade on delete: an
            // employee record removal should not leave an orphaned key that
            // could still verify signatures.
            $table->foreignId('employee_id')->constrained('employees', 'employee_id')->cascadeOnDelete();

            // Stable per-installation identifier generated on the device. One
            // row per device; rebinding updates the existing row rather than
            // accumulating rows, so a stale keypair can never still verify.
            $table->string('device_id')->unique();

            // ECDSA P-256 public half, PEM. The private half never leaves the
            // Android Keystore TEE, which is what makes off-device forgery
            // impossible rather than merely difficult.
            $table->text('public_key');

            // Per-device HMAC secret for the hash chain. Encrypted at rest via
            // Laravel's `encrypted` cast (see DeviceKey) — note that ties it to
            // APP_KEY, so rotating APP_KEY without re-encrypting orphans every
            // device and forces a rebind.
            $table->text('hmac_key');

            // Android KeyInfo security level: STRONGBOX, TRUSTED_ENVIRONMENT,
            // or SOFTWARE. Recorded rather than assumed so the hardware-backing
            // claim is auditable — an emulator reports SOFTWARE, and
            // config('crypto.require_hardware_backed_keys') decides whether
            // that is acceptable.
            $table->string('security_level')->nullable();

            // Last hmac_hash the server accepted from this device. The chain
            // verifier needs it to enforce continuity *across* sync batches,
            // not just within one — without it a device could discard history
            // and start a fresh chain each upload.
            $table->string('last_chain_hash')->nullable();

            $table->timestamp('bound_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Verification looks devices up by device_id while filtering out
            // revoked ones on every synced record.
            $table->index(['device_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_keys');
    }
};
