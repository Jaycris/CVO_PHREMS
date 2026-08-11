<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every action taken on a payroll run.
     *
     * The first thing anyone wants when asked "why did Juan's net pay change
     * between Tuesday and Thursday" is the list of who computed, recomputed,
     * finalised or unlocked the run, and when.
     */
    public function up(): void
    {
        Schema::create('payroll_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_logs');
    }
};
