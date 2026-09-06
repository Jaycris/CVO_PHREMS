<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of the paid no-punch days were given off rather than worked.
 *
 * Kept on the payslip beside days_offsite so an issued payslip keeps saying
 * what actually happened, even after the underlying record is edited or the
 * exhibit is long forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_offsite_day_off')->default(0)->after('days_offsite');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('days_offsite_day_off');
        });
    }
};
