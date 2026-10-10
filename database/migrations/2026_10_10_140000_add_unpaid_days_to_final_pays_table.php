<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The days they worked in the cutoff they left in, and when the settlement is
 * expected to be paid.
 *
 * They drop out of that payroll run entirely, so the days are settled here
 * instead — one payment rather than a part-payslip now and the rest weeks
 * later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_pays', function (Blueprint $table) {
            $table->date('unpaid_from')->nullable()->after('separation_date');
            $table->decimal('unpaid_days', 6, 2)->default(0)->after('unpaid_from');
            $table->decimal('unpaid_salary', 12, 2)->default(0)->after('unpaid_days');
            $table->decimal('unpaid_night_differential', 12, 2)->default(0)->after('unpaid_salary');
            $table->decimal('unpaid_overtime', 12, 2)->default(0)->after('unpaid_night_differential');

            // Thirty days by default, and negotiable — which is why it is a
            // date somebody can change rather than a rule in the code.
            $table->date('expected_release_on')->nullable()->after('released_on');
        });
    }

    public function down(): void
    {
        Schema::table('final_pays', function (Blueprint $table) {
            $table->dropColumn([
                'unpaid_from', 'unpaid_days', 'unpaid_salary',
                'unpaid_night_differential', 'unpaid_overtime', 'expected_release_on',
            ]);
        });
    }
};
