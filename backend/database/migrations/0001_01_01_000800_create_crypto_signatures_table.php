<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_crypto_signature (signature_id PK, attendance_id FK -> tbl_attendance,
     *      hmac_hash, prev_hash, ecdsa_signature, verified).
     * One attendance record carries exactly one signature (1:1), hence the
     * unique constraint on attendance_id to match the ERD cardinality.
     */
    public function up(): void
    {
        Schema::create('crypto_signatures', function (Blueprint $table) {
            $table->unsignedBigInteger('signature_id')->autoIncrement()->primary();
            $table->foreignId('attendance_id')->unique()->constrained('attendances', 'attendance_id');
            $table->string('hmac_hash')->nullable();
            $table->string('prev_hash')->nullable();
            $table->text('ecdsa_signature')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_signatures');
    }
};