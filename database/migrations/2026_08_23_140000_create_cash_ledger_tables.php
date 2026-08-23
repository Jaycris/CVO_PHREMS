<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // A category belongs to one side of the ledger. "Rent" is never
            // money coming in, and offering it when recording a client payment
            // is how a ledger ends up miscategorised.
            $table->enum('direction', ['in', 'out']);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['name', 'direction']);
        });

        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->index();
            $table->enum('direction', ['in', 'out'])->index();

            $table->foreignId('cash_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->decimal('amount', 14, 2);

            // Whatever the paper says — an OR number, an invoice number, a
            // bank reference. Free text because every counterparty numbers
            // things differently.
            $table->string('reference')->nullable();
            $table->string('note')->nullable();

            // Who typed it in. A money record that cannot say who entered a
            // figure is not much of a record.
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Left empty for now: every entry is typed by hand. These exist so
            // a finalized payroll run, a released cash advance or a paid
            // reimbursement can post itself here later without a migration and
            // without the risk of being entered twice.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['entry_date', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_categories');
    }
};
