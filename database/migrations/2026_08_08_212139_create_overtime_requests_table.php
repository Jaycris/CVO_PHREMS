<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overtime only reaches payroll once a manager has approved it, so the
     * approved hours are stored separately from what was requested — a manager
     * can approve fewer hours than were claimed.
     *
     * Mirrors leave_requests minus the CEO tier: overtime is a single
     * Team Leader / Manager decision.
     */
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');

            $table->decimal('hours_requested', 5, 2);
            $table->decimal('hours_approved', 5, 2)->nullable();
            $table->text('reason')->nullable();

            $table->enum('status', ['pending_manager', 'approved', 'declined', 'cancelled'])
                ->default('pending_manager');

            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('manager_decision', ['approved', 'declined'])->nullable();
            $table->timestamp('manager_decided_at')->nullable();
            $table->string('manager_note')->nullable();

            // Stamped when a payroll run consumes these hours, so a recompute can
            // release them and the same overtime is never paid twice. The foreign
            // key is added with the payroll_runs table in a later milestone.
            $table->unsignedBigInteger('consumed_payroll_run_id')->nullable()->index();

            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
