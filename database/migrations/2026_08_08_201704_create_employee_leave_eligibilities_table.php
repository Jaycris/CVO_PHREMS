<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records whether a given employee is entitled to a given leave type.
     *
     * Absence of a row means "use the default for that accrual mode" —
     * event-based types (Maternity, Paternity) default to NOT eligible so they
     * are never handed out automatically, everything else defaults to eligible
     * so existing SL/VL behaviour is unchanged.
     */
    public function up(): void
    {
        Schema::create('employee_leave_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_eligible')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_eligibilities');
    }
};
