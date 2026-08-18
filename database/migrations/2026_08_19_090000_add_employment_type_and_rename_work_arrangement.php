<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three separate facts that were being carried by one and a half fields.
     *
     *   Employment Type    Full-time or Part-time — how many hours are owed.
     *   Workplace Type     Onsite, Hybrid or Remote — where the work happens.
     *   Employment Status  Probationary, Regular, Contract, Training — the
     *                      standing of the engagement.
     *
     * Employment Type had nowhere to live at all. Workplace Type existed under
     * the name Work Arrangement and is renamed here to match what HR calls it.
     * Employment Status keeps its column and gains Contract in validation.
     */
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'work_arrangement') && ! Schema::hasColumn('employees', 'workplace_type')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('work_arrangement', 'workplace_type');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'employment_type')) {
                // Nullable rather than defaulted to Full-time: a blank means
                // nobody has said, which is honest for records created before
                // the field existed. Guessing would put a figure on every one
                // of them that reads as though HR had confirmed it.
                $table->string('employment_type')->nullable()->after('employment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employment_type');
        });

        if (Schema::hasColumn('employees', 'workplace_type')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('workplace_type', 'work_arrangement');
            });
        }
    }
};
