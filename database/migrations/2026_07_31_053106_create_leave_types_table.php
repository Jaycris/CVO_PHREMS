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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 10)->unique();
            $table->enum('accrual_mode', ['annual_upfront', 'monthly_accrual', 'event_based'])->default('annual_upfront');
            $table->decimal('default_annual_credits', 6, 2)->default(0);
            $table->decimal('monthly_accrual_rate', 6, 3)->nullable();
            $table->unsignedTinyInteger('accrual_day_of_month')->nullable();
            $table->boolean('resets_annually')->default(true);
            $table->boolean('allow_carry_over')->default(false);
            $table->boolean('allow_cash_conversion')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
