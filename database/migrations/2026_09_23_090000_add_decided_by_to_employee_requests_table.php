<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually decided a request.
 *
 * manager_id is who it was routed to, which stops being the same person the
 * moment the CEO or COO decides one waiting on a manager. Recording only the
 * manager would credit a decision to somebody who never made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_requests', function (Blueprint $table) {
            $table->foreignId('decided_by_user_id')->nullable()->after('manager_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by_user_id');
        });
    }
};
