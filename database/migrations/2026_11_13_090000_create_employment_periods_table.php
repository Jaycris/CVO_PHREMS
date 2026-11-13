<?php

use App\Models\Employee;
use App\Models\EmploymentPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A row per stretch of employment, instead of one hire date and one last day.
 *
 * Somebody terminated in October and rehired in November overwrote both: the
 * record then read as unbroken service from a date that was no longer true,
 * and the only trace of the first period was that their payslips stopped for
 * a fortnight. Nothing stated it, so nobody could answer "how long has she
 * worked here" without reading a payroll history.
 *
 * The employee's own hire_date and separation_date stay where they are —
 * payroll, leave and the whole system read them, and changing that in one
 * migration would be a rewrite with a payroll run due. This sits alongside
 * them as the history they cannot hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('started_on');
            // Open-ended while they are still here.
            $table->date('ended_on')->nullable();

            // The same vocabulary as separation_type, so a period and the
            // employee record cannot describe the same leaving differently.
            $table->string('end_reason', 40)->nullable();
            $table->string('note', 255)->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'started_on']);
        });

        $this->backfill();
    }

    /**
     * One period per employee, from what the record says today.
     *
     * Everybody already here gets the history they would have had if this
     * table had always existed. Somebody rehired before today still shows one
     * unbroken period, because that is genuinely all the record knows — the
     * earlier dates were overwritten and no migration can recover them. HR
     * can split those by hand where it matters.
     */
    protected function backfill(): void
    {
        Employee::query()
            ->whereNotNull('hire_date')
            ->orderBy('id')
            ->chunkById(200, function ($employees) {
                foreach ($employees as $employee) {
                    EmploymentPeriod::create([
                        'employee_id' => $employee->id,
                        'started_on' => $employee->hire_date->toDateString(),
                        'ended_on' => $employee->separation_date?->toDateString(),
                        'end_reason' => $employee->separation_date ? $employee->separation_type : null,
                        'note' => 'Recorded from the employee profile when employment history was added.',
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_periods');
    }
};
