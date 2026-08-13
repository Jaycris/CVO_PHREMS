<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The thirteenth month, on its own line.
     *
     * Kept apart from basic_earned deliberately: basic_earned is what next
     * year's thirteenth month sums, and folding this payment in would have the
     * bonus quietly grow its own base every year.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('thirteenth_month_pay', 12, 2)->default(0)->after('leave_conversion_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('thirteenth_month_pay');
        });
    }
};
