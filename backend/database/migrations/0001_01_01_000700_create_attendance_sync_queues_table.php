<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_attendance_sync_queue (queue_id PK, attendance_id FK -> tbl_attendance,
     *      device_id, queued_at, synced_at, sync_status)
     */
    public function up(): void
    {
        Schema::create('attendance_sync_queues', function (Blueprint $table) {
            $table->bigIncrements('queue_id');
            $table->foreignId('attendance_id')->constrained('attendances', 'attendance_id');
            $table->string('device_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('sync_status')->default('queued');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sync_queues');
    }
};
