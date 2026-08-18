<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What kind of CRM work this person does — Inbound, Outbound and so on.
     *
     * The CRM has had this field all along; HRIS did not, so there was nothing
     * to auto-fill it from. Nullable, because it only means anything for people
     * who work in the CRM at all, and blank simply means the CRM admin picks it
     * by hand as they do today.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('work_type')->nullable()->after('phone_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('work_type');
        });
    }
};
