<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a position as one people can report to (Team Leader, Manager,
     * COO, CEO). Rank belongs on the position rather than the department —
     * a department groups people by function (Sales, Production), while
     * "who can approve my leave" follows job title.
     *
     * Defaults to false so no existing position silently becomes a manager.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('is_supervisory')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('is_supervisory');
        });
    }
};
