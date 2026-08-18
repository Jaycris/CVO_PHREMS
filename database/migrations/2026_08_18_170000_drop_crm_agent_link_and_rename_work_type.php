<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two corrections now the bridge between the systems has settled.
     *
     * The CRM stores our employee id against its user, so there is nothing left
     * for a CRM agent id to do. Keeping it would leave a second, weaker way to
     * identify the same person — and the whole point of the employee id is that
     * there is only one.
     *
     * Work Type also turned out to be the wrong name: what HR actually records
     * is whether someone works On-site or Remote, which is an arrangement
     * rather than a type of work.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'crm_linked_by_user_id')) {
                $table->dropConstrainedForeignId('crm_linked_by_user_id');
            }
        });

        // The index has to go before the column it covers. MySQL drops it along
        // with the column; SQLite refuses and leaves the table unusable, which
        // is what the test database runs on.
        if (Schema::hasColumn('employees', 'crm_agent_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex('employees_crm_agent_id_index');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            foreach (['crm_agent_id', 'crm_linked_at', 'crm_agent_snapshot'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('employees', 'work_type') && ! Schema::hasColumn('employees', 'work_arrangement')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('work_type', 'work_arrangement');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'work_arrangement')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('work_arrangement', 'work_type');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->string('crm_agent_id')->nullable()->after('employee_id')->index();
            $table->timestamp('crm_linked_at')->nullable()->after('crm_agent_id');
            $table->foreignId('crm_linked_by_user_id')->nullable()->after('crm_linked_at')
                ->constrained('users')->nullOnDelete();
            $table->json('crm_agent_snapshot')->nullable()->after('crm_linked_by_user_id');
        });
    }
};
