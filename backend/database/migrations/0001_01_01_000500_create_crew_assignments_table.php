<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_crew_assignment (assignment_id PK, crew_id FK -> tbl_crew,
     *      employee_id FK -> tbl_employee, date_assigned, status)
     */
    public function up(): void
    {
        Schema::create('crew_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('assignment_id')->autoIncrement()->primary();
            $table->foreignId('crew_id')->constrained('crews', 'crew_id');
            $table->foreignId('employee_id')->constrained('employees', 'employee_id');
            $table->date('date_assigned')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_assignments');
    }
};