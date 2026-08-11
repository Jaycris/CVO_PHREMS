<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PhilHealth is a straight percentage rather than a table: the premium is
     * the rate applied to monthly basic pay, clamped between a floor and a
     * ceiling, then split between employee and employer.
     *
     * One row per effective period. The rate has been scheduled to rise year on
     * year, so the ability to load next year's row ahead of time matters.
     */
    public function up(): void
    {
        Schema::create('philhealth_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('premium_rate', 6, 4);
            $table->decimal('salary_floor', 12, 2);
            $table->decimal('salary_ceiling', 12, 2);
            // Employee's portion of the premium — 0.5 for the usual even split.
            $table->decimal('employee_share_ratio', 6, 4)->default(0.5);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('philhealth_rates');
    }
};
