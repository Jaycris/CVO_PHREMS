<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One repayment taken from one payslip. Deleting these rows is what makes a
     * payroll recompute safe: the balance is derived, so reversing a run simply
     * removes its payments and the debt reappears untouched.
     *
     * payroll_run_id and payslip_id are unconstrained for now; the foreign keys
     * arrive with the payroll tables in a later milestone. The unique pair still
     * guarantees an advance can only be charged once per payslip.
     */
    public function up(): void
    {
        Schema::create('cash_advance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payroll_run_id')->index();
            $table->unsignedBigInteger('payslip_id')->index();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->timestamps();

            $table->unique(['cash_advance_id', 'payslip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_payments');
    }
};
