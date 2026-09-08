<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_audit_log (audit_id PK, actor_id FK -> tbl_employee [self-ref],
     *      action_type, description, timestamp)
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('audit_id');
            $table->foreignId('actor_id')->constrained('employees', 'employee_id');
            $table->string('action_type');
            $table->text('description')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
