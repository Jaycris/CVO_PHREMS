<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One employee's pay for one run, stored as a snapshot rather than
     * recomputed on demand.
     *
     * Rates and counters are kept alongside the money so a payslip can be
     * explained years later without the rate tables or attendance still
     * agreeing with what they said at the time. employee_snapshot holds the
     * name, department and government numbers as they were, so a payslip
     * reprinted after someone marries or transfers still prints correctly.
     */
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Rates at 4dp — the rate itself is never a money figure, and
            // rounding it to centavos before multiplying loses accuracy.
            $table->decimal('daily_rate', 12, 4)->default(0);
            $table->decimal('hourly_rate', 12, 4)->default(0);
            $table->decimal('minute_rate', 12, 4)->default(0);
            $table->decimal('basic_salary', 12, 2)->default(0);

            $table->unsignedSmallInteger('days_expected')->default(0);
            $table->unsignedSmallInteger('days_present')->default(0);
            $table->unsignedSmallInteger('days_absent')->default(0);
            $table->unsignedSmallInteger('days_paid_leave')->default(0);
            $table->unsignedSmallInteger('days_lwop')->default(0);
            $table->unsignedSmallInteger('days_rest')->default(0);
            $table->unsignedSmallInteger('night_diff_days')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->unsignedInteger('over_break_minutes')->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);

            $table->decimal('basic_pay', 12, 2)->default(0);
            $table->decimal('absence_deduction', 12, 2)->default(0);
            // What 13th month sums: basic pay net of absences.
            $table->decimal('basic_earned', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('night_differential_pay', 12, 2)->default(0);
            $table->decimal('allowance', 12, 2)->default(0);
            $table->decimal('adjustments_earning', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);

            $table->decimal('late_deduction', 12, 2)->default(0);
            $table->decimal('undertime_deduction', 12, 2)->default(0);
            $table->decimal('over_break_deduction', 12, 2)->default(0);
            $table->decimal('sss_employee', 12, 2)->default(0);
            $table->decimal('philhealth_employee', 12, 2)->default(0);
            $table->decimal('pagibig_employee', 12, 2)->default(0);
            $table->decimal('total_contributions', 12, 2)->default(0);
            $table->decimal('taxable_income', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('cash_advance_deduction', 12, 2)->default(0);
            $table->decimal('adjustments_deduction', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);

            $table->decimal('net_pay', 12, 2)->default(0);

            // Stored though they never touch net pay: the remittance forms need
            // them, and reconstructing them later from historical brackets is
            // considerably harder than keeping them now.
            $table->decimal('sss_employer', 12, 2)->default(0);
            $table->decimal('sss_employee_compensation', 12, 2)->default(0);
            $table->decimal('philhealth_employer', 12, 2)->default(0);
            $table->decimal('pagibig_employer', 12, 2)->default(0);

            $table->json('employee_snapshot')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
