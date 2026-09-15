<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay for sales agents that goes out outside the payroll run.
 *
 * Some remote agents only earn their basic once their CRM sales reach a target.
 * The company keeps them out of payroll runs entirely and pays them by hand when
 * the target is met. Before this, that money left the company with no record of
 * who it went to — the only trace was a line in a bank statement.
 *
 * Each row is one payment to one agent. Recording it also writes a Money Out
 * entry to the cash ledger, so company money stays complete, and sends the agent
 * a pay slip, so they have the same paper trail a payroll payslip gives everyone
 * else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // The month the pay is for, "2026-09". Kept apart from paid_on
            // because September's basic is often paid in October.
            $table->string('month', 7);

            // What it was for, shown on the slip: "Basic salary — sales target reached".
            $table->string('description', 160);

            $table->decimal('amount', 12, 2);

            // Their CRM month-to-date at the time, for the record. Optional:
            // the payment stands on its own whether or not somebody noted it.
            $table->decimal('mtd_usd', 12, 2)->nullable();

            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->string('note', 255)->nullable();

            // The Money Out entry this payment wrote. Null on delete rather than
            // cascade the other way: the payment is the source of truth.
            $table->foreignId('cash_entry_id')->nullable()->constrained('cash_entries')->nullOnDelete();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // When the slip went to the agent. Null means they have not been
            // sent it — usually because they have no login.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_payments');
    }
};
