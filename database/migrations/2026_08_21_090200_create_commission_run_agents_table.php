<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a run covers.
     *
     * Chosen rather than derived. Commission frequency suggests the list — it
     * pre-ticks the monthly agents on a monthly run — but the last word is a
     * person's, because "who is on commission this period" is a question about
     * arrangements and mid-period joiners that no rule in this app can answer
     * correctly every time.
     *
     * Locked in when the run is computed: the slips then exist and are the
     * record. This table is what compute reads, so removing someone before
     * computing removes them from the run entirely.
     */
    public function up(): void
    {
        Schema::create('commission_run_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['commission_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_run_agents');
    }
};
