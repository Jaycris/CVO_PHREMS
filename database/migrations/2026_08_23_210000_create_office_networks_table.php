<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_networks', function (Blueprint $table) {
            $table->id();

            // What a person would call it — "Main office, PLDT line". Without a
            // label this becomes a list of numbers nobody dares delete because
            // nobody remembers what any of them was for.
            $table->string('label');

            // A single address (203.0.113.5) or a range in CIDR form
            // (203.0.113.0/24). Ranges matter: an office rarely has one fixed
            // address, and typing in every possibility is how the list goes
            // stale.
            $table->string('ip_address', 64);

            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_networks');
    }
};
