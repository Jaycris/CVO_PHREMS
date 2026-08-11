<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anything a human adds to a payslip by hand: a bonus, a reimbursement, a
     * correction from a previous cutoff, a deduction the system has no concept
     * of.
     *
     * Deliberately kept apart from the computed figures, and deliberately NOT
     * deleted when a run is recomputed. Recompute rebuilds everything the
     * system works out for itself; typing a bonus in twice because someone
     * pressed compute again is exactly the failure this separation prevents.
     */
    public function up(): void
    {
        Schema::create('payslip_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['earning', 'deduction']);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_adjustments');
    }
};
