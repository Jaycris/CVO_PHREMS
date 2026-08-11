<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One payroll for one cutoff, moving draft → computed → finalized → paid.
     *
     * The unique key on type and period is the guard against the worst mistake
     * available here: two finalised runs for the same cutoff, and everyone paid
     * twice. Cancelled runs are hard-deleted rather than kept, so they do not
     * hold that key against a corrected re-run.
     *
     * Who did each transition and when is recorded on the row itself, because
     * these are the four questions asked whenever a payroll is queried.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('run_type', ['regular', 'thirteenth_month', 'final_pay', 'special'])->default('regular');
            $table->enum('cutoff', ['first', 'second'])->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');

            $table->enum('status', ['draft', 'computed', 'finalized', 'paid', 'cancelled'])->default('draft');

            $table->timestamp('computed_at')->nullable();
            $table->foreignId('computed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['run_type', 'period_start', 'period_end'], 'payroll_runs_period_unique');
            $table->index('status');
            $table->index('pay_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
