<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_slip_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_payment_id')->nullable();
            $table->unsignedBigInteger('crm_agent_id')->nullable();
            $table->index(['crm_payment_id', 'crm_agent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_slip_lines', function (Blueprint $table) {
            $table->dropIndex(['crm_payment_id', 'crm_agent_id']);
            $table->dropColumn(['crm_payment_id', 'crm_agent_id']);
        });
    }
};
