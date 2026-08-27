<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who put this credit here.
 *
 * Accrual and the annual reset are the system's own doing and leave this null.
 * An opening balance is not: somebody decides a person starts with nine days,
 * and vacation leave converts to cash at year end, so that decision is worth
 * money and belongs against a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_credit_transactions', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('note')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_credit_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
