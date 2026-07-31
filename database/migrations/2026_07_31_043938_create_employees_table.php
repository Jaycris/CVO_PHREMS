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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // HR-filled at creation
            $table->string('employee_id')->unique();
            $table->string('phone_name')->nullable();
            $table->string('company_email')->unique();
            $table->foreignId('position_id')->constrained();
            $table->foreignId('department_id')->constrained();
            $table->date('hire_date');
            $table->decimal('basic_salary', 10, 2);
            $table->decimal('allowance', 10, 2)->default(0);
            $table->enum('commission_scheme', ['Tier 1', 'Tier 2', 'Tier 3'])->nullable();
            $table->decimal('quota', 10, 2)->nullable();
            $table->enum('employment_status', ['Probationary', 'Training', 'Regular'])->default('Probationary');
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->nullOnDelete();

            // Employee self-filled via onboarding form
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('birthdate')->nullable();
            $table->text('address')->nullable();
            $table->string('personal_contact_number')->nullable();
            $table->string('personal_email')->nullable();
            $table->enum('civil_status', ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'])->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_number')->nullable();
            $table->string('tin_number')->nullable();
            $table->string('sss_number')->nullable();
            $table->string('philhealth_number')->nullable();
            $table->string('pagibig_number')->nullable();

            // Onboarding / account linkage
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
