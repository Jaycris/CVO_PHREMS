<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee asking to work from home on particular days.
     *
     * The days live in their own table rather than as a start/end pair, because
     * "Monday and Thursday next week" is as common a request as "the whole of
     * next week" — a range cannot express the first, and a row per day
     * expresses both. It also makes "is anyone home on Tuesday" one query
     * rather than a scan.
     *
     * Not leave. The employee still works a full day and still clocks in, so
     * nothing here touches payroll. It records where they are, not whether they
     * are owed pay.
     */
    public function up(): void
    {
        Schema::create('work_from_home_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('reason');
            $table->enum('status', ['pending_manager', 'approved', 'declined', 'cancelled'])
                ->default('pending_manager');

            // Whoever the request routes to. Null when the employee has no
            // manager on file, and it then falls to whoever oversees this
            // company-wide rather than becoming undecidable.
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('work_from_home_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_from_home_request_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');

            $table->timestamps();

            // One row per request per date. Nothing stops two separate requests
            // naming the same day, which the service refuses on its own terms
            // with a message rather than a constraint violation.
            $table->unique(['work_from_home_request_id', 'work_date']);
            $table->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_from_home_days');
        Schema::dropIfExists('work_from_home_requests');
    }
};
