<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How often this agent's commission is worked out.
     *
     * Held here because the CRM does not know it. The CRM answers for whatever
     * period it is asked about; deciding which agents belong in which run is
     * this app's job.
     *
     * 'none' is the default, and deliberately so. Most employees are not on
     * commission at all, and defaulting everyone to monthly would put the whole
     * company into every run and ask the CRM about people it has never heard
     * of — a hundred needless 404s to read past.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('commission_frequency', ['none', 'monthly', 'biweekly'])
                ->default('none')
                ->after('commission_scheme');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('commission_frequency');
        });
    }
};
