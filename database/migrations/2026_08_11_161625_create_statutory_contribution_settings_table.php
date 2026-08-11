<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which cutoff each government contribution comes out of.
     *
     * The whole month's contribution is taken in one go rather than halved,
     * which is how the company's payroll already works. Each type is targeted
     * separately because SSS and PhilHealth are commonly deducted on different
     * cutoffs, and getting this wrong shifts an employee's take-home pay
     * between the 15th and the 30th.
     *
     * is_active exists so a contribution can be switched off company-wide
     * without deleting its rate table and losing the history.
     */
    public function up(): void
    {
        Schema::create('statutory_contribution_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('code', ['sss', 'philhealth', 'pagibig', 'bir'])->unique();
            $table->enum('deduct_on_cutoff', ['first', 'second'])->default('second');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_contribution_settings');
    }
};
