<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Without a separation date a payroll run has no way to exclude
            // ex-employees, and a mid-cutoff leaver cannot be pro-rated.
            $table->date('separation_date')->nullable()->index()->after('employment_status');
            $table->string('separation_reason')->nullable()->after('separation_date');
            $table->boolean('include_in_payroll')->default(true)->after('separation_reason');

            // Not every employee is covered by every contribution, so enrollment
            // is per-employee rather than assumed from having an ID number.
            $table->boolean('sss_enrolled')->default(true)->after('include_in_payroll');
            $table->boolean('philhealth_enrolled')->default(true)->after('sss_enrolled');
            $table->boolean('pagibig_enrolled')->default(true)->after('philhealth_enrolled');

            // false = statutory minimum wage earner / otherwise exempt from withholding.
            $table->boolean('bir_withholding_enrolled')->default(true)->after('pagibig_enrolled');

            // Allowance is de minimis (non-taxable) by default, the common BPO setup.
            $table->boolean('allowance_taxable')->default(false)->after('bir_withholding_enrolled');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'separation_date',
                'separation_reason',
                'include_in_payroll',
                'sss_enrolled',
                'philhealth_enrolled',
                'pagibig_enrolled',
                'bir_withholding_enrolled',
                'allowance_taxable',
            ]);
        });
    }
};
