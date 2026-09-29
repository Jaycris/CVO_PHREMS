<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which hours a half day covers, taken from the employee's own shift.
 *
 * Stored rather than worked out later: a schedule can change, and the approval
 * trail has to keep saying what was actually asked for and agreed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->time('half_day_start')->nullable()->after('half_day_period');
            $table->time('half_day_end')->nullable()->after('half_day_start');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['half_day_start', 'half_day_end']);
        });
    }
};
