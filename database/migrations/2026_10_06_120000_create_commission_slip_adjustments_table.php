<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amounts added to or taken off a commission slip by hand.
 *
 * The CRM owns the sales; this owns everything the CRM cannot know — a bonus
 * agreed verbally, a correction, a deduction nobody wants to model. They
 * survive a recompute, because a figure somebody typed is the one thing the
 * recompute must not throw away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_slip_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_slip_id')->constrained()->cascadeOnDelete();
            // earning | deduction
            $table->string('type');
            $table->string('label', 120);
            $table->decimal('amount', 12, 2);
            $table->string('note', 200)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Kept as text as well: who agreed it must still read sensibly after
            // that person's account is gone.
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        Schema::table('commission_slips', function (Blueprint $table) {
            $table->decimal('adjustments_earning', 12, 2)->default(0)->after('advance_deduction');
            $table->decimal('adjustments_deduction', 12, 2)->default(0)->after('adjustments_earning');
        });
    }

    public function down(): void
    {
        Schema::table('commission_slips', function (Blueprint $table) {
            $table->dropColumn(['adjustments_earning', 'adjustments_deduction']);
        });

        Schema::dropIfExists('commission_slip_adjustments');
    }
};
