<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money an employee spent on the company's behalf, claimed back on payroll.
     *
     * A reimbursement is not pay. The employee is being handed back their own
     * money, so it is not taxed, not part of basic pay, and not counted toward
     * the thirteenth month — which is why it lives on its own line rather than
     * as an allowance or an adjustment.
     *
     * The claim is only attached to a payslip once it has been approved, so a
     * pending or declined one never reaches payroll. payslip_id is what stops
     * the same receipt being paid twice.
     */
    public function up(): void
    {
        Schema::create('reimbursement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount_requested', 12, 2);
            // An approver may allow less than was claimed — a receipt that
            // covers a personal item alongside a company one, say.
            $table->decimal('amount_approved', 12, 2)->nullable();

            $table->date('expense_date');
            $table->string('category')->default('other');
            $table->text('description');
            $table->string('receipt_path')->nullable();

            $table->enum('status', ['pending', 'approved', 'declined', 'cancelled'])->default('pending');

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            // Set when payroll picks it up. Its presence means "already paid".
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->date('paid_on')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_requests');
    }
};
