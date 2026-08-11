<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The escape hatch. Every other permission can be taken away through the
     * UI, which means without this it is possible to remove the last account
     * able to administer the system and lock everyone out permanently.
     *
     * A super admin passes every permission check regardless of position or
     * individual grants, and the Users screen refuses to demote the last one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
