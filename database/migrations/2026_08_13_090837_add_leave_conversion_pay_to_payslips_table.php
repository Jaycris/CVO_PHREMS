<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unused leave cashed out at year end, kept on its own line rather than
     * folded into basic pay.
     *
     * It is not basic pay and must not be: basic_earned is what 13th month
     * sums, and a conversion is a payout of days already earned in a previous
     * year. Adding it there would inflate next year's 13th month by a figure
     * nobody could trace.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('leave_conversion_pay', 12, 2)->default(0)->after('allowance');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('leave_conversion_pay');
        });
    }
};
