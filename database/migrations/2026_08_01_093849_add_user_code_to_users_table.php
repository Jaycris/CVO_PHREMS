<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Human-facing account reference (USR-1234), shown in the UI as "User ID"
     * so staff never have to quote the autoincrement primary key.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_code', 20)->nullable()->unique()->after('id');
        });

        // Backfill existing accounts so the column can be relied on everywhere.
        User::whereNull('user_code')->get()->each(function (User $user): void {
            $user->forceFill(['user_code' => User::generateUserCode()])->save();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['user_code']);
            $table->dropColumn('user_code');
        });
    }
};
