<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The handful of payroll figures that are policy rather than law — the
     * night differential divisor and rate, the leave conversion divisor,
     * whether undertime and over-break actually deduct.
     *
     * Key/value because these are read individually and changed rarely; a
     * column each would mean a migration every time the company revises one.
     */
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value')->nullable();
            $table->string('label');
            $table->string('description')->nullable();
            // 'choice' is for settings that pick between named options rather
            // than holding a number. Listed here as well as in the later ALTER
            // so a database built from scratch — the test suite's, and any
            // future install — has it from the start.
            $table->enum('type', ['decimal', 'integer', 'boolean', 'time', 'choice'])->default('decimal');
            $table->string('group')->default('General');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
