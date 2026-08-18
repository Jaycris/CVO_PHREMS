<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who tied this employee to which CRM account, and when.
     *
     * The CRM cannot hold our employee id, so the link lives here and nowhere
     * else. That makes it worth an audit trail: "we cannot guarantee this CRM
     * record is for this employee" stops being true the moment a named person
     * confirms it on a named date, and the snapshot records what they saw when
     * they did — so a later change in the CRM can be spotted rather than
     * silently followed.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('crm_linked_at')->nullable()->after('crm_agent_id');
            $table->foreignId('crm_linked_by_user_id')->nullable()->after('crm_linked_at')
                ->constrained('users')->nullOnDelete();
            $table->json('crm_agent_snapshot')->nullable()->after('crm_linked_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_linked_by_user_id');
            $table->dropColumn(['crm_linked_at', 'crm_agent_snapshot']);
        });
    }
};
