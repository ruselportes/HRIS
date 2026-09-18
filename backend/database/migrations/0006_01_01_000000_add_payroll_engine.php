<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll engine (Phase 8 — UC-08, STD TC-06). Schema decided with the team:
 *
 * holidays — NEW (15th table). Holidays are proclaimed yearly by Executive
 *   Order and some dates move, so they are data, not config. One row per
 *   holiday; two regular holidays on one date make a double holiday. type is
 *   'regular' (Art. 94: paid when unworked, 200% when worked) or 'special'
 *   (no work, no pay; 130% when worked).
 *
 * overtime_requests — start_time, end_time. Roll call records arrival only,
 *   so overtime and night differential come from an approved request's
 *   window. An end before the start runs past midnight.
 *
 * payroll_details — the payslip itemised. A single `deductions` figure cannot
 *   show SSS, PhilHealth, Pag-IBIG and tax separately, which workers are
 *   entitled to see and each agency's remittance needs. `deductions` stays as
 *   the employee total. Also: employer shares (for remittance), basic vs
 *   premium pay (13th month pay is computed on basic), unworked regular
 *   holiday hours, why tax is what it is, whether the row may be paid, and
 *   the day-by-day breakdown the figures came from.
 *
 * payrolls — run_code ("2026-09-A") and one row per employee per period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->bigIncrements('holiday_id');
            $table->date('date')->index();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->timestamps();
            $table->unique(['date', 'name']);
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('ot_date');
            $table->time('end_time')->nullable()->after('start_time');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('run_code', 20)->nullable()->after('employee_id')->index();
            $table->unique(['employee_id', 'pay_period_start']);
        });

        Schema::table('payroll_details', function (Blueprint $table) {
            $table->decimal('unworked_holiday_hours', 8, 2)->default(0)->after('holiday_hours');
            $table->decimal('basic_pay', 12, 2)->default(0)->after('unworked_holiday_hours');
            $table->decimal('premium_pay', 12, 2)->default(0)->after('basic_pay');
            $table->decimal('sss_employee', 12, 2)->default(0)->after('premium_pay');
            $table->decimal('philhealth_employee', 12, 2)->default(0)->after('sss_employee');
            $table->decimal('pagibig_employee', 12, 2)->default(0)->after('philhealth_employee');
            $table->decimal('withholding_tax', 12, 2)->default(0)->after('pagibig_employee');
            $table->decimal('other_deductions', 12, 2)->default(0)->after('withholding_tax');
            $table->decimal('sss_employer', 12, 2)->default(0)->after('deductions');
            $table->decimal('philhealth_employer', 12, 2)->default(0)->after('sss_employer');
            $table->decimal('pagibig_employer', 12, 2)->default(0)->after('philhealth_employer');
            $table->string('tax_note', 160)->nullable()->after('pagibig_employer');
            $table->string('readiness', 20)->default('ready')->after('tax_note');
            $table->json('blocked_reasons')->nullable()->after('readiness');
            $table->json('breakdown')->nullable()->after('blocked_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn([
                'unworked_holiday_hours', 'basic_pay', 'premium_pay',
                'sss_employee', 'philhealth_employee', 'pagibig_employee', 'withholding_tax', 'other_deductions',
                'sss_employer', 'philhealth_employer', 'pagibig_employer',
                'tax_note', 'readiness', 'blocked_reasons', 'breakdown',
            ]);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'pay_period_start']);
            $table->dropIndex(['run_code']);
            $table->dropColumn('run_code');
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });

        Schema::dropIfExists('holidays');
    }
};
