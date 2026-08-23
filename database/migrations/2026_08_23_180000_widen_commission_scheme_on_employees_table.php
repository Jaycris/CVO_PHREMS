<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Was enum('Tier 1','Tier 2','Tier 3'). Those three names were
            // invented in the HRIS and never existed in the CRM, which calls
            // its plans something else entirely — so the database was refusing
            // to store the only values that are actually correct.
            //
            // Now a plain string, validated against the commission_schemes
            // table, so matching the CRM is HR typing a name rather than a
            // migration and a release.
            $table->string('commission_scheme', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('commission_scheme', ['Tier 1', 'Tier 2', 'Tier 3'])->nullable()->change();
        });
    }
};
