<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SSS contribution table, one row per Monthly Salary Credit bracket.
     *
     * Shares are stored as pesos rather than computed from a rate, because SSS
     * publishes them as a table and the printed figures are what the remittance
     * form is checked against. A rate that rounds differently from the circular
     * would be wrong on every line.
     *
     * Contributions above the regular MSC ceiling go to the mandatory provident
     * fund (WISP), which is reported separately on the remittance, so those
     * pesos are kept in their own columns rather than folded into the total.
     *
     * salary_to is nullable for the open-ended top bracket. effective_from /
     * effective_to let a new circular be loaded before it takes effect, and
     * keep historical payslips reproducible afterwards.
     */
    public function up(): void
    {
        Schema::create('sss_brackets', function (Blueprint $table) {
            $table->id();
            $table->decimal('salary_from', 12, 2);
            $table->decimal('salary_to', 12, 2)->nullable();
            $table->decimal('monthly_salary_credit', 12, 2);

            $table->decimal('employee_share', 12, 2);
            $table->decimal('employer_share', 12, 2);
            $table->decimal('employee_compensation', 12, 2)->default(0);

            $table->decimal('employee_mpf_share', 12, 2)->default(0);
            $table->decimal('employer_mpf_share', 12, 2)->default(0);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['effective_from', 'effective_to']);
            $table->index('salary_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sss_brackets');
    }
};
