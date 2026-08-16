<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App-wide preferences that are not payroll — how many rows a table shows,
     * and whatever joins it later.
     *
     * Deliberately a separate table from payroll_settings. Mixing them would
     * put "rows per page" on the Payroll Settings screen next to the SSS
     * brackets, and the accountant who owns that screen does not own this.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value')->nullable();
            $table->string('label');
            $table->string('description')->nullable();
            $table->enum('type', ['integer', 'boolean', 'text', 'choice'])->default('text');
            $table->string('group')->default('General');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
