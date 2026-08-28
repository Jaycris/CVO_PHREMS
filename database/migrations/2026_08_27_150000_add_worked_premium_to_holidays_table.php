<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What working on this particular holiday is worth, set on the holiday itself.
 *
 * Deriving it from the Labor Code type looked tidier and was wrong for this
 * company. Their list has no Regular Holidays on it at all — Christmas Day and
 * New Year's Day are entered against the American calendar as paid days off,
 * because that is how the company treats them, and a rule keyed off the type
 * would have paid nothing for working either.
 *
 * Whoever adds the holiday knows what it is worth to them. This is where they
 * say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            // A percentage of one day's pay, on top of the day itself: 100 for
            // double pay, 30 for a special non-working day, 0 for nothing extra.
            $table->unsignedSmallInteger('worked_premium_percent')->default(0)->after('type');
        });

        // Existing rows get the Labor Code default for their type, which is the
        // answer most of them want and all of them can change.
        DB::table('holidays')->where('type', 'regular')->update(['worked_premium_percent' => 100]);
        DB::table('holidays')->where('type', 'special_non_working')->update(['worked_premium_percent' => 30]);
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('worked_premium_percent');
        });
    }
};
