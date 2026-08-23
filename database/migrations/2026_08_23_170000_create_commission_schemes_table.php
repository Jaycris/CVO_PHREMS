<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // What the CRM calls the same scheme, when the two differ. The CRM
            // owns every commission figure, so if these lists drift the HRIS
            // is quietly describing an agent's pay by a name nobody else uses.
            $table->string('crm_key')->nullable();

            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Keep whatever is already on employee records. "Tier 1/2/3" was
        // invented in the HRIS and hard-coded in two forms; it may or may not
        // match the CRM, but dropping it would blank live records.
        $existing = DB::table('employees')
            ->whereNotNull('commission_scheme')
            ->where('commission_scheme', '!=', '')
            ->distinct()
            ->orderBy('commission_scheme')
            ->pluck('commission_scheme');

        foreach ($existing as $index => $name) {
            DB::table('commission_schemes')->insert([
                'name' => $name,
                'description' => 'Carried over from the old built-in list. Check this matches the CRM.',
                'is_active' => true,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_schemes');
    }
};
