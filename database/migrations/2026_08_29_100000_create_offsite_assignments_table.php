<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days somebody worked for the company away from the punch clock.
 *
 * A trade exhibit, a client visit, a day helping on a booth. They are working
 * and they are being paid, but there is no clock to press and nobody is going
 * to remember to press one on a stand all day. Without this the day reads as an
 * absence and quietly takes a day's pay off them.
 *
 * Held per employee even though it is set up per event, so HR can take one
 * person off the list without unpicking the rest, and so payroll can answer
 * "why was this day paid" for one employee without joining through an event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offsite_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            // What it was. Shown on the payslip breakdown and in the list, so
            // "why is 8 September paid with no time in" has an answer on file.
            $table->string('reason');

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offsite_assignments');
    }
};
