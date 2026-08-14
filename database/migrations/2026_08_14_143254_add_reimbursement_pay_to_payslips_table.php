<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses paid back to the employee, on their own line.
     *
     * Deliberately not folded into allowance or basic pay. It is not income:
     * taxing it would charge someone for spending their own money, and counting
     * it in basic_earned would inflate their thirteenth month by whatever they
     * happened to travel that year.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('reimbursement_pay', 12, 2)->default(0)->after('leave_conversion_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('reimbursement_pay');
        });
    }
};
