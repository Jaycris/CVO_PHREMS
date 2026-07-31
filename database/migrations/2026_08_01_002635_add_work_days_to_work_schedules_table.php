<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payroll needs to know which calendar days an employee was *expected* to
     * work, otherwise a rest day is indistinguishable from an absence.
     */
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            // ISO-8601 day numbers, 1 = Monday .. 7 = Sunday.
            // MySQL forbids a DEFAULT on json columns, so existing rows are
            // backfilled below and WorkSchedule::workDays() supplies the fallback.
            $table->json('work_days')->nullable()->after('coffee_break_minutes');

            // null = derive from whether the shift overlaps the 22:00-06:00 window.
            $table->boolean('night_differential_eligible')->nullable()->after('work_days');
        });

        DB::table('work_schedules')
            ->whereNull('work_days')
            ->update(['work_days' => json_encode([1, 2, 3, 4, 5])]);
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'night_differential_eligible']);
        });
    }
};
