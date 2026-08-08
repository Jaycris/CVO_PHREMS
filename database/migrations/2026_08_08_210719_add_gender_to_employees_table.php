<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable because existing records predate the field and because it is
     * not required to run payroll — it informs HR's judgement on entitlements
     * such as Maternity and Paternity leave, which stay a manual decision.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('gender', ['Male', 'Female'])->nullable()->after('birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
