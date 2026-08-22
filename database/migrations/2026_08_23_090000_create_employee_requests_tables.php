<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One place an employee files anything that needs approving.
     *
     * Work from home was built as its own table and its own page, which was
     * fine until the second kind of request appeared. Rather than a page per
     * kind, the kind is a row: HR adds a request type, says whether it needs
     * dates, and it shows up on the form.
     *
     * Existing work from home requests are carried across below, so nothing
     * filed already is lost.
     */
    public function up(): void
    {
        Schema::create('request_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            // What the employee is told when they pick this type — the place to
            // put "attach the quotation" or "give the purpose in full".
            $table->string('instructions')->nullable();

            // Some requests are about days (work from home, a shift change) and
            // some are not (a certificate, a headset). The form follows this.
            $table->boolean('needs_dates')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_type_id')->constrained()->cascadeOnDelete();

            $table->string('details');
            $table->enum('status', ['pending_manager', 'approved', 'declined', 'cancelled'])
                ->default('pending_manager');

            // Whoever it routes to. Null when the employee has no manager on
            // file, and it then falls to whoever oversees requests company-wide
            // rather than sitting undecidable.
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['request_type_id', 'status']);
        });

        Schema::create('employee_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_request_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamps();

            $table->unique(['employee_request_id', 'work_date']);
            $table->index('work_date');
        });

        $now = now();

        DB::table('request_types')->insert([
            [
                'code' => 'work_from_home', 'name' => 'Work From Home',
                'description' => 'Work from home on particular days.',
                'instructions' => 'Ask in advance. A full working day either way — you still clock in as usual.',
                'needs_dates' => true, 'is_active' => true, 'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'schedule_change', 'name' => 'Schedule / Shift Change',
                'description' => 'Move to a different shift, swap with someone, or change a day off.',
                'instructions' => 'Say which shift you want and who you have agreed the swap with, if anyone.',
                'needs_dates' => true, 'is_active' => true, 'sort_order' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'certificate_of_employment', 'name' => 'Certificate of Employment',
                'description' => 'A COE, proof of income, or bank certificate.',
                'instructions' => 'Say what the document is for and who it should be addressed to.',
                'needs_dates' => false, 'is_active' => true, 'sort_order' => 3,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'equipment', 'name' => 'Equipment / IT Request',
                'description' => 'A headset, laptop, monitor or software access.',
                'instructions' => 'Say what you need and why the one you have will not do.',
                'needs_dates' => false, 'is_active' => true, 'sort_order' => 4,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        // Carry across anything already filed, so nobody loses a pending ask.
        if (Schema::hasTable('work_from_home_requests')) {
            $wfhType = DB::table('request_types')->where('code', 'work_from_home')->value('id');

            foreach (DB::table('work_from_home_requests')->get() as $old) {
                $id = DB::table('employee_requests')->insertGetId([
                    'employee_id' => $old->employee_id,
                    'request_type_id' => $wfhType,
                    'details' => $old->reason,
                    'status' => $old->status,
                    'manager_id' => $old->manager_id,
                    'decided_at' => $old->decided_at,
                    'decision_note' => $old->decision_note,
                    'created_at' => $old->created_at,
                    'updated_at' => $old->updated_at,
                ]);

                $days = DB::table('work_from_home_days')
                    ->where('work_from_home_request_id', $old->id)
                    ->get();

                foreach ($days as $day) {
                    DB::table('employee_request_days')->insert([
                        'employee_request_id' => $id,
                        'work_date' => $day->work_date,
                        'created_at' => $day->created_at,
                        'updated_at' => $day->updated_at,
                    ]);
                }
            }

            Schema::dropIfExists('work_from_home_days');
            Schema::dropIfExists('work_from_home_requests');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_request_days');
        Schema::dropIfExists('employee_requests');
        Schema::dropIfExists('request_types');
    }
};
