<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody is owed after their last day.
 *
 * The thirteenth month they earned up to that day, less anything they still
 * owe, held until clearance is signed off. Their final salary is not in here:
 * the regular payroll run already pays the days they worked, and paying them
 * again here would be paying twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_pays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('separation_date');
            // Which year's thirteenth month this settles, so December's run can
            // tell it has already been paid.
            $table->unsignedSmallInteger('for_year');

            $table->decimal('basic_earned', 12, 2)->default(0);
            $table->decimal('thirteenth_month', 12, 2)->default(0);
            $table->decimal('cash_advance_balance', 12, 2)->default(0);
            $table->decimal('commission_advance_balance', 12, 2)->default(0);
            $table->decimal('other_deduction', 12, 2)->default(0);
            $table->string('other_deduction_label')->nullable();
            $table->decimal('net_amount', 12, 2)->default(0);

            // held | cleared | released | cancelled
            $table->string('status')->default('held');
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('released_on')->nullable();
            // Sent to their personal address: the company mailbox is gone by
            // the time this is paid.
            $table->timestamp('emailed_at')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One settlement per person per year: a second would pay the same
            // thirteenth month twice.
            $table->unique(['employee_id', 'for_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_pays');
    }
};
