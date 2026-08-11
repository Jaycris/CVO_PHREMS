<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pag-IBIG uses two rate bands by salary, and both sides are capped by
     * applying the rate to a maximum contribution base rather than to actual
     * pay — which is why the cap is stored as a base and not as a peso ceiling.
     *
     * salary_to is nullable for the open-ended upper band.
     */
    public function up(): void
    {
        Schema::create('pagibig_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('salary_from', 12, 2);
            $table->decimal('salary_to', 12, 2)->nullable();
            $table->decimal('employee_rate', 6, 4);
            $table->decimal('employer_rate', 6, 4);
            $table->decimal('max_contribution_base', 12, 2);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['effective_from', 'effective_to']);
            $table->index('salary_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagibig_rates');
    }
};
