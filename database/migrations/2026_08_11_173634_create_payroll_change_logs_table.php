<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who changed a payroll figure, when, and what it was before.
     *
     * These settings quietly change every employee's take-home pay, and a wrong
     * rate is usually noticed weeks later when someone queries their payslip.
     * Without this there is no way back from "payroll looks wrong" to "the
     * PhilHealth rate was changed on the 3rd".
     *
     * Labels and values are stored as they were displayed rather than as field
     * names and raw numbers, so an entry still reads correctly years later even
     * if the field is renamed or removed.
     */
    public function up(): void
    {
        Schema::create('payroll_change_logs', function (Blueprint $table) {
            $table->id();
            $table->string('area');
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Kept alongside the relation so the log still names someone after
            // their account is deleted.
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('area');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_change_logs');
    }
};
