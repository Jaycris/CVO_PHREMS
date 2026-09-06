<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the day was worked away from the office, or given off in lieu.
 *
 * Both are paid days nobody clocks in for, so payroll treats them the same and
 * always will. The difference is what the record says happened, and that is
 * worth getting right: describing a rest day given after a weekend exhibit as
 * "worked off-site" puts a false statement on a payslip to save a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offsite_assignments', function (Blueprint $table) {
            $table->string('kind')->default('worked')->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('offsite_assignments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
