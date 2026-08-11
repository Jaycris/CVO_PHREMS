<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes an advance that came through the request-and-approval flow
     * from one HR entered directly — typically arranged offline or predating
     * the system. Both are valid, but only one carries an approval trail, and
     * the register should not imply otherwise.
     *
     * Existing rows default to hr_recorded because they were entered before the
     * request flow existed.
     */
    public function up(): void
    {
        Schema::table('cash_advances', function (Blueprint $table) {
            $table->enum('source', ['requested', 'hr_recorded'])
                ->default('hr_recorded')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('cash_advances', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
