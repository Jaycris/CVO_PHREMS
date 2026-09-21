<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A welcome text for a new hire, ticked by HR on the employee form and sent
 * once onboarding is complete and the password is set.
 *
 * welcome_sms_sent_at is what makes it once: nothing sends while it is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('welcome_sms')->default(false)->after('onboarding_completed_at');
            $table->timestamp('welcome_sms_sent_at')->nullable()->after('welcome_sms');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['welcome_sms', 'welcome_sms_sent_at']);
        });
    }
};
