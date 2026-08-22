<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run covers a period, not a month.
     *
     * Some agents are paid commission twice a month and some monthly, and where
     * the split falls varies. A single period_month column could express none of
     * that, so it becomes a real start and end — which covers a calendar month,
     * a payroll cutoff, a fortnight, or a one-off, without this table needing to
     * know which of those it is looking at.
     *
     * run_type is a label rather than a rule. It exists so a monthly run and a
     * bi-weekly run over overlapping dates are different rows, and so the list
     * can say which is which.
     */
    public function up(): void
    {
        Schema::table('commission_runs', function (Blueprint $table) {
            $table->enum('run_type', ['monthly', 'biweekly', 'custom'])->default('monthly')->after('id');
            $table->date('period_start')->nullable()->after('run_type');
            $table->date('period_end')->nullable()->after('period_start');
            $table->string('label')->nullable()->after('period_end');
        });

        // Existing runs were whole calendar months; give them the dates that
        // were always implied.
        foreach (DB::table('commission_runs')->whereNotNull('period_month')->get() as $run) {
            $start = \Illuminate\Support\Carbon::parse($run->period_month)->startOfMonth();

            DB::table('commission_runs')->where('id', $run->id)->update([
                'run_type' => 'monthly',
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'label' => $start->format('F Y'),
            ]);
        }

        Schema::table('commission_runs', function (Blueprint $table) {
            $table->dropUnique('commission_runs_period_month_unique');
            $table->dropColumn('period_month');

            $table->date('period_start')->nullable(false)->change();
            $table->date('period_end')->nullable(false)->change();

            // Two runs of the same kind over the same dates would send an agent
            // two slips for the same work.
            $table->unique(['run_type', 'period_start', 'period_end'], 'commission_runs_period_unique');
            $table->index('period_start');
        });
    }

    public function down(): void
    {
        Schema::table('commission_runs', function (Blueprint $table) {
            $table->date('period_month')->nullable()->after('id');
        });

        foreach (DB::table('commission_runs')->get() as $run) {
            DB::table('commission_runs')->where('id', $run->id)->update([
                'period_month' => \Illuminate\Support\Carbon::parse($run->period_start)->startOfMonth()->toDateString(),
            ]);
        }

        Schema::table('commission_runs', function (Blueprint $table) {
            $table->dropUnique('commission_runs_period_unique');
            $table->dropIndex(['period_start']);
            $table->dropColumn(['run_type', 'period_start', 'period_end', 'label']);
            $table->unique('period_month');
        });
    }
};
