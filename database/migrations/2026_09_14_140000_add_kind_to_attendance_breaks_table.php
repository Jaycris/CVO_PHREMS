<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of break somebody took.
 *
 * Until now a break was a break: one button, one row, no record of whether it
 * was lunch, a coffee, or a two-minute trip to the toilet. The total was right
 * and everything underneath it was guesswork, so "who keeps disappearing" had
 * no answer except somebody's memory.
 *
 * Nullable, and existing rows are left alone rather than guessed at. A break
 * punched last month genuinely has no kind on record, and filling one in would
 * be inventing evidence about somebody's day — the one thing a time record must
 * never do.
 *
 * Deliberately no change to pay. The allowance is still lunch plus coffee and
 * every minute over it is still deducted exactly as before; restroom trips were
 * already counted in that total as ordinary breaks. This only puts a name on
 * what was already being measured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_breaks', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('attendance_day_id');
            $table->index(['attendance_day_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_breaks', function (Blueprint $table) {
            $table->dropIndex(['attendance_day_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
