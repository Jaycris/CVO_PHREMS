<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The printed lines of a payslip.
     *
     * Derived entirely from the payslip's own columns and thrown away and
     * rebuilt on every compute, so they can never drift from the figures they
     * describe. They exist so the layout — which lines, in which order, under
     * which heading — is data rather than a hard-coded template.
     */
    public function up(): void
    {
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->enum('section', ['earning', 'deduction', 'employer']);
            $table->string('label');
            $table->string('detail')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payslip_id', 'section', 'sort_order'], 'payslip_lines_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
    }
};
