<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One month's commissions, moving draft → computed → finalized → sent.
     *
     * The CRM works the figures out; this run is the moment HRIS writes them
     * down. Once computed they stop moving, which is the whole point — an agent
     * shown a live figure that changes overnight has no slip, only a screen.
     *
     * Unique on the month so two finalised runs for August cannot both exist
     * and send an agent two different slips.
     */
    public function up(): void
    {
        Schema::create('commission_runs', function (Blueprint $table) {
            $table->id();

            // Stored as the first of the month. A date column sorts and ranges
            // properly, which a "2026-08" string does not.
            $table->date('period_month');

            $table->enum('status', ['draft', 'computed', 'finalized', 'sent', 'cancelled'])->default('draft');

            $table->timestamp('computed_at')->nullable();
            $table->foreignId('computed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('agent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('total_usd', 14, 2)->default(0);
            $table->decimal('total_php', 14, 2)->default(0);
            $table->decimal('total_card_hold', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique('period_month');
            $table->index('status');
        });

        Schema::create('commission_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_run_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Kept as text as well as an id: the answer to "who did this" must
            // survive the account being deleted.
            $table->string('user_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_run_logs');
        Schema::dropIfExists('commission_runs');
    }
};
