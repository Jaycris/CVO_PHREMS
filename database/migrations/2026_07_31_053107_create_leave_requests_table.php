<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days_requested');
            $table->text('reason')->nullable();
            $table->boolean('is_lwop')->default(false);

            $table->enum('status', ['pending_manager', 'pending_ceo', 'approved', 'declined'])->default('pending_manager');

            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('manager_decision', ['approved', 'declined'])->nullable();
            $table->timestamp('manager_decided_at')->nullable();

            $table->foreignId('ceo_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('ceo_decision', ['approved', 'declined'])->nullable();
            $table->timestamp('ceo_decided_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
