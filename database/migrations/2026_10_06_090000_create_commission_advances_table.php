<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money advanced to an agent against commission they have not earned yet, and
 * taken back out of their commission slips.
 *
 * Kept apart from the payroll cash advance even though the shape is the same:
 * one is repaid out of salary on a cutoff, the other out of commission on a
 * run, and an agent can be repaying both at once without either eating the
 * other's instalment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no')->unique();

            $table->decimal('principal_amount', 12, 2);
            // What to take each run. The balance is taken when this is null.
            $table->decimal('amount_per_run', 12, 2)->nullable();

            $table->date('released_on');
            // active | on_hold | paid | cancelled
            $table->string('status')->default('active');
            $table->text('note')->nullable();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('commission_advance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_slip_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->timestamps();

            // Recomputing a run must not charge the agent twice. This is the
            // hard guarantee behind that, not the code that writes it.
            $table->unique(['commission_advance_id', 'commission_slip_id'], 'commission_advance_once_per_slip');
            $table->index('commission_run_id');
        });

        Schema::table('commission_slips', function (Blueprint $table) {
            $table->decimal('advance_deduction', 12, 2)->default(0)->after('card_hold_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_slips', function (Blueprint $table) {
            $table->dropColumn('advance_deduction');
        });

        Schema::dropIfExists('commission_advance_payments');
        Schema::dropIfExists('commission_advances');
    }
};
