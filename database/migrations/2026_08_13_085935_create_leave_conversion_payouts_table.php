<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unused leave paid out in cash at year end.
     *
     * The annual reset already writes the days off an employee's balance; this
     * records what those days were worth and which payslip carried them.
     *
     * The unique key on leave_credit_transaction_id is the whole point: a
     * conversion is paid exactly once, forever. Without it a recompute, a
     * reopened run, or the reset running twice would each pay the same days
     * again — and unlike most payroll mistakes this one is invisible, because
     * the leave balance was already zero.
     */
    public function up(): void
    {
        Schema::create('leave_conversion_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_credit_transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('days', 8, 2);
            $table->decimal('daily_rate', 12, 4);
            $table->decimal('amount', 12, 2);
            $table->year('for_year');
            $table->timestamps();

            $table->index(['employee_id', 'for_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_conversion_payouts');
    }
};
