<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Half a day off: a morning at the clinic, an afternoon at the embassy.
 *
 * days_requested becomes a decimal because half of a day is 0.5, and the
 * payslip's day counts follow for the same reason — half a day of leave
 * without pay deducts half a day's pay, not one or none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('days_requested', 5, 2)->default(0)->change();
            // null means the whole day. Otherwise which half they are away for,
            // so the DTR and the approver both know when to expect them.
            $table->string('half_day_period')->nullable()->after('days_requested');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('days_absent', 6, 2)->default(0)->change();
            $table->decimal('days_paid_leave', 6, 2)->default(0)->change();
            $table->decimal('days_lwop', 6, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('half_day_period');
            $table->unsignedSmallInteger('days_requested')->default(0)->change();
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_absent')->default(0)->change();
            $table->unsignedSmallInteger('days_paid_leave')->default(0)->change();
            $table->unsignedSmallInteger('days_lwop')->default(0)->change();
        });
    }
};
