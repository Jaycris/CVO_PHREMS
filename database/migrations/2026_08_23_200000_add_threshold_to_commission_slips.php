<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_slips', function (Blueprint $table) {
            // What the agent's threshold was set to when this slip was
            // computed, whether they were exempt from it, and what it actually
            // took off. Frozen with every other figure, because an agent
            // querying a slip six weeks later is asking about the rules as they
            // stood then, not as they stand now.
            $table->decimal('commission_threshold', 14, 2)->nullable()->after('card_hold_amount');
            $table->boolean('threshold_exempt')->default(false)->after('commission_threshold');
            $table->decimal('threshold_applied', 14, 2)->nullable()->after('threshold_exempt');
        });

        Schema::table('commission_slip_lines', function (Blueprint $table) {
            // Per sale, so the statement can show its working rather than
            // leaving the reader to wonder why one row earned less per peso
            // than the row under it.
            $table->decimal('threshold_applied', 14, 2)->nullable()->after('markup_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_slips', function (Blueprint $table) {
            $table->dropColumn(['commission_threshold', 'threshold_exempt', 'threshold_applied']);
        });

        Schema::table('commission_slip_lines', function (Blueprint $table) {
            $table->dropColumn('threshold_applied');
        });
    }
};
