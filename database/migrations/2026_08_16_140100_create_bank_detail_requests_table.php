<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every attempt to move where someone's salary lands.
     *
     * The previous values are copied onto the row rather than looked up later:
     * once the change is approved the employee record holds the new account,
     * and without a copy there is no way to answer "what was it before".
     *
     * Rows are kept after a decision. This is the audit trail for the one
     * change in the system that redirects money.
     */
    public function up(): void
    {
        Schema::create('bank_detail_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('bank_name');
            $table->string('bank_account_name');
            $table->string('bank_account_number');

            $table->string('previous_bank_name')->nullable();
            $table->string('previous_bank_account_name')->nullable();
            $table->string('previous_bank_account_number')->nullable();

            $table->string('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'declined', 'cancelled'])->default('pending');

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_detail_requests');
    }
};
