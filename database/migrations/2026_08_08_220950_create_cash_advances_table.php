<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A loan against future payroll, repaid a fixed amount per cutoff.
     *
     * Note there is deliberately NO remaining_balance column — the balance is
     * derived from the payment rows. A stored figure would drift the first time
     * a payroll run is recomputed and its payments reversed.
     */
    public function up(): void
    {
        Schema::create('cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30)->nullable()->unique();

            $table->decimal('principal_amount', 12, 2);
            $table->decimal('amount_per_cutoff', 12, 2);

            // Deduction begins on the first payroll period ending on or after this.
            $table->date('start_date');

            $table->enum('status', ['active', 'paid', 'on_hold', 'cancelled'])->default('active');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advances');
    }
};
