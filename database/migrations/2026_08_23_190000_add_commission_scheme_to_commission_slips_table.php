<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_slips', function (Blueprint $table) {
            // The plan the CRM computed this slip under, frozen at compute
            // alongside every other figure. A slip is a document somebody is
            // paid against, so it has to say which plan produced it — reading
            // the employee's current scheme instead would relabel last
            // quarter's slips the day HR moves that agent to a new tier.
            $table->string('commission_scheme')->nullable()->after('work_type');

            // The rate bands as they stood, for the same reason.
            $table->json('scheme_rules')->nullable()->after('commission_scheme');
        });
    }

    public function down(): void
    {
        Schema::table('commission_slips', function (Blueprint $table) {
            $table->dropColumn(['commission_scheme', 'scheme_rules']);
        });
    }
};
