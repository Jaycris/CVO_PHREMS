<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee's application for a cash advance, decided by the CEO/COO. The
     * advance itself is only created on approval — cash_advance_id stays null
     * until then, so a pending or declined request never appears in the
     * repayment register and can never be deducted from a payslip.
     *
     * The approved amount is stored apart from the requested one because HR, the
     * accountant and the CEO/COO may all amend what will actually be released.
     * Keeping both means the employee's original ask is never overwritten.
     */
    public function up(): void
    {
        Schema::create('cash_advance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount_requested', 12, 2);
            $table->decimal('amount_approved', 12, 2)->nullable();

            // How the advance comes back out of payroll. The per-cutoff figure
            // is derived from this and the amount rather than typed, so the two
            // can never disagree.
            $table->enum('deduction_plan', ['split_two_cutoffs', 'full_next_payroll'])
                ->default('split_two_cutoffs');

            $table->date('needed_by')->nullable();
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'declined', 'cancelled'])->default('pending');

            // Who last changed the amount or the plan, kept separate from the
            // decision so an HR amendment is not mistaken for an approval.
            $table->foreignId('amended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('amended_at')->nullable();

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            // Set once the request is approved and the advance is opened.
            $table->foreignId('cash_advance_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_requests');
    }
};
