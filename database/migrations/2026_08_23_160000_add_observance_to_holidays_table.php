<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            // Whose holiday it is. The company is US-facing, so it observes
            // some American holidays alongside the Philippine ones, and a list
            // that cannot say which is which is a list nobody trusts.
            $table->string('observance', 32)->default('philippines')->after('type')->index();
        });

        Schema::table('holidays', function (Blueprint $table) {
            // Was an enum of the three Philippine Labor Code categories, which
            // a US federal holiday does not belong to. Widened to a string so
            // adding a category is an application change rather than a
            // migration — the app already validates against Holiday::TYPES.
            $table->string('type', 32)->default('regular')->change();
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropIndex(['observance']);
            $table->dropColumn('observance');
        });
    }
};
