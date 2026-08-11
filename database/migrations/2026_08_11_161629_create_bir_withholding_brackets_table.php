<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BIR withholding tax, as the revised withholding table: within a bracket
     * the tax is a fixed base plus a percentage of whatever the taxable income
     * exceeds the bracket floor.
     *
     * period is stored because the same table is published for daily, weekly,
     * semi-monthly and monthly payrolls, and the company pays semi-monthly.
     * Keeping it lets a future run type look up its own table instead of
     * pro-rating one that does not divide cleanly.
     */
    public function up(): void
    {
        Schema::create('bir_withholding_brackets', function (Blueprint $table) {
            $table->id();
            $table->enum('period', ['daily', 'weekly', 'semi_monthly', 'monthly'])->default('semi_monthly');
            $table->decimal('income_from', 12, 2);
            $table->decimal('income_to', 12, 2)->nullable();
            $table->decimal('base_tax', 12, 2)->default(0);
            $table->decimal('excess_rate', 6, 4)->default(0);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['period', 'effective_from', 'effective_to'], 'bir_period_effective_index');
            $table->index('income_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bir_withholding_brackets');
    }
};
