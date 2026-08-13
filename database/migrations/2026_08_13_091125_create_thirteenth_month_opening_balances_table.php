<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Basic pay earned before this system was keeping payslips.
     *
     * Without it every employee's first 13th month is understated by however
     * many months predate go-live — someone hired in January whose payslips
     * only start in August would be paid five twelfths of what they are owed.
     * That is a very visible error to make in December.
     *
     * HR enters the figure once per employee per year, from the old payroll
     * records. It is added to whatever this system has since recorded.
     */
    public function up(): void
    {
        Schema::create('thirteenth_month_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->year('for_year');
            $table->decimal('basic_earned', 12, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'for_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thirteenth_month_opening_balances');
    }
};
