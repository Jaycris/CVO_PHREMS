<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of a cutoff's paid days were worked away from the clock.
 *
 * Kept on the payslip rather than looked up later, for the same reason the
 * holiday counts are: the off-site record can be corrected or removed months
 * afterwards, and a payslip already issued has to keep explaining itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_offsite')->default(0)->after('days_rest');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('days_offsite');
        });
    }
};
