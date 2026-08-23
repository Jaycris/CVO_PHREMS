<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('name');

            // The three categories the Labor Code recognises. They are not
            // decoration — each pays differently, and a "special working day"
            // is specifically the government saying this is an ordinary day.
            $table->enum('type', ['regular', 'special_non_working', 'special_working'])
                ->default('regular');

            $table->string('note')->nullable();
            $table->timestamps();

            // Two holidays genuinely can share a date — a local one alongside a
            // national one — so the date alone cannot be unique. The pair can.
            $table->unique(['date', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
