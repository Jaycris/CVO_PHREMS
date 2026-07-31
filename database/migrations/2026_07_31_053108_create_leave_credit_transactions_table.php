<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('transaction_date');
            $table->decimal('amount', 6, 2);
            $table->enum('reason', [
                'initial_grant',
                'annual_reset',
                'monthly_accrual',
                'year_end_carry_over',
                'year_end_cash_conversion',
                'leave_taken',
                'adjustment',
            ]);
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_credit_transactions');
    }
};
