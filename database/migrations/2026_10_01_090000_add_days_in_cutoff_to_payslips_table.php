<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long the whole cutoff was, beside how much of it this person was employed
 * for.
 *
 * Kept on the payslip rather than worked out later: the company's cutoff length
 * is a setting, and a payslip has to keep explaining itself after somebody
 * changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_in_cutoff')->default(0)->after('days_expected');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('days_in_cutoff');
        });
    }
};
