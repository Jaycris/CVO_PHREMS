<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When somebody last did anything in PHREMS.
 *
 * Stamped on request, so it answers "is this person in the system right now" —
 * which is the question HR actually asks before ringing somebody about a
 * payslip, or before wondering whether an account is being used at all.
 *
 * It is not a measure of work. Somebody on a call for four hours touches PHREMS
 * once all morning, and a booth team at an exhibit never opens it. Attendance
 * is what says whether somebody worked; this only says whether they were on
 * this screen.
 *
 * Null for everybody at first, which reads as "never signed in" — true of an
 * account created this morning and of one nobody has ever used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('is_active');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
