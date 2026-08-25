<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Whether this person uses the punch clock.
            //
            // Set per employee, because it does not follow anything else: some
            // remote staff clock in and some do not, and the same is true
            // across production, sales and admin. Department, position and
            // workplace type all fail to predict it.
            //
            // Payroll counts a scheduled day with no punch-in as an absence, so
            // without this a fixed-pay employee who never clocks in loses
            // almost their whole salary — on PHP 30,000 a cutoff came out at
            // PHP 1,363.64 instead of PHP 15,000.
            //
            // Defaults true. Most people do clock in, and nobody's pay changes
            // until somebody deliberately says otherwise.
            $table->boolean('tracks_attendance')->default(true)->after('workplace_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('tracks_attendance');
        });
    }
};
