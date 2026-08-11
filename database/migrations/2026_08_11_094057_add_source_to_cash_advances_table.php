<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * source distinguishes an advance that came through the request-and-approval
     * flow from one HR entered directly — typically arranged offline or
     * predating the system. Both are valid, but only one carries an approval
     * trail, and the register should not imply otherwise.
     *
     * deduction_plan is carried over from the request so the register can say
     * how the advance is being recovered without recomputing it from the
     * per-cutoff amount.
     */
    public function up(): void
    {
        Schema::table('cash_advances', function (Blueprint $table) {
            $table->enum('source', ['requested', 'hr_recorded'])
                ->default('hr_recorded')
                ->after('status');

            $table->enum('deduction_plan', ['split_two_cutoffs', 'full_next_payroll'])
                ->default('split_two_cutoffs')
                ->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('cash_advances', function (Blueprint $table) {
            $table->dropColumn(['source', 'deduction_plan']);
        });
    }
};
