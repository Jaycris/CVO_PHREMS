<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee's application for a cash advance, approved Manager then
     * CEO/COO before any money is committed. The advance itself is only created
     * on final approval — cash_advance_id stays null until then, so a pending or
     * declined request never appears in the repayment register.
     *
     * Approved amounts are stored separately from requested ones: an approver
     * may release less than was asked for, or stretch the repayment.
     */
    public function up(): void
    {
        Schema::create('cash_advance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount_requested', 12, 2);
            $table->decimal('per_cutoff_requested', 12, 2);
            $table->decimal('amount_approved', 12, 2)->nullable();
            $table->decimal('per_cutoff_approved', 12, 2)->nullable();

            $table->date('needed_by')->nullable();
            $table->text('reason');

            $table->enum('status', ['pending_manager', 'pending_ceo', 'approved', 'declined', 'cancelled'])
                ->default('pending_manager');

            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('manager_decision', ['approved', 'declined'])->nullable();
            $table->timestamp('manager_decided_at')->nullable();
            $table->string('manager_note')->nullable();

            $table->foreignId('ceo_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('ceo_decision', ['approved', 'declined'])->nullable();
            $table->timestamp('ceo_decided_at')->nullable();
            $table->string('ceo_note')->nullable();

            // Set once the request is fully approved and the advance is opened.
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
