<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * leave_types.monthly_accrual_rate is decimal(6,3) (e.g. 0.833), but the ledger
     * stored amounts at decimal(6,2) — silently truncating every monthly accrual to
     * 0.83 and leaving employees ~0.04 days short per year.
     */
    public function up(): void
    {
        Schema::table('leave_credit_transactions', function (Blueprint $table) {
            $table->decimal('amount', 8, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_credit_transactions', function (Blueprint $table) {
            $table->decimal('amount', 6, 2)->change();
        });
    }
};
