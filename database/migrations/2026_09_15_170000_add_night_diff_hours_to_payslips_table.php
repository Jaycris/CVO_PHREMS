<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Night differential is paid per hour, so the payslip keeps the hours it was
 * paid for. night_diff_days stays: it is still how many nights were worked, and
 * payslips from before this change only have that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('night_diff_hours', 7, 2)->default(0)->after('night_diff_days');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('night_diff_hours');
        });
    }
};
