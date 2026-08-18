<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the CRM knows this person.
     *
     * Nullable on purpose: when it is empty the commission lookup falls back to
     * the company email, so the integration works before anyone has backfilled
     * a single row. Fill it in only where the CRM keys agents by its own id.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('crm_agent_id')->nullable()->after('employee_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['crm_agent_id']);
            $table->dropColumn('crm_agent_id');
        });
    }
};
