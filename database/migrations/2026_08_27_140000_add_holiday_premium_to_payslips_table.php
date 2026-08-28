<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The extra earned for working on a holiday.
 *
 * Its own line rather than folded into basic pay, because an employee asked to
 * come in on National Heroes Day should be able to see what that was worth,
 * and because the two rates differ — a regular holiday doubles the day, a
 * special non-working day adds three tenths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('holiday_premium_pay', 12, 2)->default(0)->after('night_differential_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('holiday_premium_pay');
        });
    }
};
