<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One agent's commission slip for one month, frozen at compute time.
     *
     * Every money column is nullable rather than defaulting to zero, and that
     * matters more here than anywhere else in the system: zero is a claim that
     * the CRM worked out nothing was owed, while null only says the CRM did not
     * send that field. Printing 0.00 for the second is a lie on a document an
     * agent is paid against.
     *
     * fetch_error carries the reason a particular agent could not be read — a
     * CRM user with no HRIS Employee ID, most likely — so a run of ninety-nine
     * good slips is not blocked by the hundredth, and the one that failed says
     * why on its own row.
     */
    public function up(): void
    {
        Schema::create('commission_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Name, employee id, department and position as they were the day
            // this was computed, so a later rename does not rewrite history.
            $table->json('employee_snapshot')->nullable();

            $table->string('agent_name')->nullable();
            $table->string('team')->nullable();
            $table->string('work_type')->nullable();

            $table->decimal('mtd', 14, 2)->nullable();
            $table->decimal('target', 14, 2)->nullable();
            $table->decimal('mtd_percent', 8, 2)->nullable();

            $table->decimal('service_commission', 14, 2)->nullable();
            $table->decimal('markup_commission', 14, 2)->nullable();
            $table->decimal('usd_total', 14, 2)->nullable();
            $table->decimal('exchange_rate', 12, 4)->nullable();
            $table->decimal('php_total', 14, 2)->nullable();

            $table->decimal('card_hold_percent', 8, 2)->nullable();
            $table->decimal('card_hold_amount', 14, 2)->nullable();
            $table->decimal('net_commission', 14, 2)->nullable();

            // Told apart on purpose: no rows and no statement are different
            // problems, and the slip says so differently.
            $table->boolean('statement_supplied')->default(false);
            $table->unsignedInteger('transaction_count')->default(0);

            $table->string('fetch_error')->nullable();

            // Set when the slip is actually sent. Until then the agent cannot
            // see it, exactly as with a payslip.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            $table->unique(['commission_run_id', 'employee_id']);
            $table->index('notified_at');
        });

        Schema::create('commission_slip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_slip_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('sold_date')->nullable();
            $table->string('brand')->nullable();
            $table->string('client')->nullable();
            $table->string('book_title')->nullable();
            $table->string('service')->nullable();
            $table->string('payment_method')->nullable();

            $table->decimal('sale_amount', 14, 2)->nullable();
            $table->decimal('service_amount', 14, 2)->nullable();
            $table->decimal('markup_amount', 14, 2)->nullable();
            $table->decimal('service_commission', 14, 2)->nullable();
            $table->decimal('markup_commission', 14, 2)->nullable();
            $table->decimal('usd_total', 14, 2)->nullable();
            $table->decimal('php_total', 14, 2)->nullable();
            $table->decimal('card_hold_amount', 14, 2)->nullable();
            $table->decimal('net_commission', 14, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_slip_lines');
        Schema::dropIfExists('commission_slips');
    }
};
