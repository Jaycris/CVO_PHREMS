<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed somebody's attendance, when, and what it said before.
 *
 * Attendance decides pay. An edit with no trail is a dispute nobody can settle
 * six months later — the employee remembers one thing, the record says another,
 * and there is no way to tell which of them is right.
 *
 * The row survives the attendance day it describes: `attendance_day_id` is
 * nulled if that day is ever deleted, and the employee and date are copied here
 * so the history still reads correctly on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attendance_day_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');

            // Who made the change. Kept even if the account is later removed,
            // which is why this nulls rather than cascades.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What the day said before and after, as {field: value} pairs.
            // Stored rather than diffed on read: the schedule behind a computed
            // figure can change, and the point of a record is that it does not.
            $table->json('before');
            $table->json('after');

            $table->string('reason');

            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
