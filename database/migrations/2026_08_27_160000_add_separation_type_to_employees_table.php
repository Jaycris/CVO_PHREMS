<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How somebody left, as a choice rather than as prose.
 *
 * The separation reason was already here and is free text, which is right for
 * the detail — "moved to Cebu", "end of project" — but useless for answering
 * "how many people resigned this year". Resigning and being terminated are
 * different facts with different consequences, and neither is reliably
 * recoverable from a sentence somebody typed.
 *
 * The reason stays, and now explains the type rather than carrying it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('separation_type')->nullable()->after('separation_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('separation_type');
        });
    }
};
