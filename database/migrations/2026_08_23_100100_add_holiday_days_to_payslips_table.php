<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // Holidays that landed on a scheduled workday and were paid without
            // being worked, and how many of those the employee turned up for.
            // Kept on the payslip so a past run still explains itself after the
            // holiday list has moved on to next year's proclamation.
            $table->unsignedSmallInteger('days_holiday')->default(0)->after('days_rest');
            $table->unsignedSmallInteger('days_holiday_worked')->default(0)->after('days_holiday');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['days_holiday', 'days_holiday_worked']);
        });
    }
};
