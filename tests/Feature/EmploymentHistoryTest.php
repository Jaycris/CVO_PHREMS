<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Somebody who left and came back.
 *
 * An employee holds one hire date and one last day, which cannot describe
 * two stretches of employment. Rehiring overwrote both: the record read as
 * unbroken service from a date that was no longer true, and the only trace of
 * the first period was that the payslips stopped for a fortnight.
 *
 * The case throughout is Hazel — hired 1 September, terminated 30 October,
 * back on 11 November.
 */
class EmploymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->hr = User::factory()->create(['is_super_admin' => true]);
        $this->hr->assignRole('Admin');
        $this->actingAs($this->hr);
    }

    protected function hazel(): Employee
    {
        return Employee::factory()->create([
            'first_name' => 'Hazel',
            'last_name' => 'Ravina',
            'hire_date' => '2026-09-01',
        ]);
    }

    #[Test]
    public function a_new_employee_starts_a_period(): void
    {
        $employee = $this->hazel();

        $period = $employee->employmentPeriods()->sole();

        $this->assertSame('2026-09-01', $period->started_on->toDateString());
        $this->assertTrue($period->isOpen());
    }

    #[Test]
    public function giving_somebody_a_last_day_closes_their_period(): void
    {
        $employee = $this->hazel();

        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);

        $period = $employee->employmentPeriods()->sole();

        $this->assertSame('2026-10-30', $period->ended_on->toDateString());
        $this->assertSame('terminated', $period->end_reason);
        $this->assertFalse($period->isOpen());
    }

    #[Test]
    public function bringing_them_back_opens_a_second_period_rather_than_rewriting_the_first(): void
    {
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);

        $employee->fresh()->rehire('2026-11-11', 'Returned to the same team');

        $periods = $employee->fresh()->employmentPeriods;

        $this->assertCount(2, $periods);

        // The first stays exactly as it was. That is the whole point — it is
        // the only record that she was ever gone.
        $this->assertSame('2026-09-01', $periods[0]->started_on->toDateString());
        $this->assertSame('2026-10-30', $periods[0]->ended_on->toDateString());
        $this->assertSame('terminated', $periods[0]->end_reason);

        $this->assertSame('2026-11-11', $periods[1]->started_on->toDateString());
        $this->assertTrue($periods[1]->isOpen());
    }

    #[Test]
    public function coming_back_clears_the_last_day_and_the_reason_together(): void
    {
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);

        $employee->fresh()->rehire('2026-11-11');

        $fresh = $employee->fresh();

        // The date is what keeps somebody out of payroll; the reason is what
        // shows on the directory. Leaving either behind shows "Terminated"
        // against somebody back at their desk, or pays nobody at all.
        $this->assertNull($fresh->separation_date);
        $this->assertNull($fresh->separation_type);
    }

    #[Test]
    public function the_hire_date_is_left_alone(): void
    {
        // Payroll, leave and the thirteenth month all read it. Whether tenure
        // restarts is a separate decision, and not one a rehire should make
        // silently.
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);

        $employee->fresh()->rehire('2026-11-11');

        $this->assertSame('2026-09-01', $employee->fresh()->hire_date->toDateString());
    }

    #[Test]
    public function somebody_still_employed_cannot_be_brought_back(): void
    {
        $employee = $this->hazel();

        try {
            $employee->rehire('2026-11-11');
            $this->fail('a second open period was allowed');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertCount(1, $employee->fresh()->employmentPeriods);
    }

    #[Test]
    public function they_cannot_come_back_before_the_day_they_left(): void
    {
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);

        try {
            $employee->fresh()->rehire('2026-10-15');
            $this->fail('a period starting before the previous one ended was allowed');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    #[Test]
    public function service_adds_the_periods_up_and_leaves_the_gap_out(): void
    {
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);
        $employee->fresh()->rehire('2026-11-11');

        // 1 Sep to 30 Oct is one whole month and change; the eleven days away
        // count for nothing. Without periods this would read as unbroken
        // service from September.
        $periods = $employee->fresh()->employmentPeriods;

        $this->assertSame(1, $periods[0]->months());
        $this->assertSame('Sep 1, 2026 – Oct 30, 2026', $periods[0]->label());
        $this->assertSame('Nov 11, 2026 – present', $periods[1]->label());
    }

    #[Test]
    public function a_period_knows_which_dates_it_covers(): void
    {
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);
        $employee->fresh()->rehire('2026-11-11');

        [$first, $second] = $employee->fresh()->employmentPeriods->all();

        $this->assertTrue($first->covers('2026-10-29'));
        // The gap belongs to neither, which is what makes it a gap.
        $this->assertFalse($first->covers('2026-11-05'));
        $this->assertFalse($second->covers('2026-11-05'));
        $this->assertTrue($second->covers('2026-11-11'));
    }

    #[Test]
    public function a_last_day_before_the_period_began_is_not_written_back(): void
    {
        // Somebody correcting a typo in the wrong direction. Closing on it
        // would leave a period ending before it starts, which no report could
        // make sense of.
        $employee = $this->hazel();

        $employee->update(['separation_date' => '2026-08-01', 'separation_type' => 'resigned']);

        $this->assertNull($employee->employmentPeriods()->sole()->ended_on);
    }

    #[Test]
    public function the_profile_shows_both_periods_and_offers_to_bring_them_back(): void
    {
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);

        Livewire::actingAs($this->hr)
            ->test('employees.show', ['employee' => $employee->fresh()])
            ->assertSee('Sep 1, 2026 – Oct 30, 2026')
            ->assertSee('Terminated')
            ->assertSee('Rehire')
            ->call('openRehireModal')
            ->set('rehireDate', '2026-11-11')
            ->set('rehireNote', 'Returned to the same team')
            ->call('rehireEmployee')
            ->assertHasNoErrors()
            ->assertSee('Nov 11, 2026 – present');

        $this->assertCount(2, $employee->fresh()->employmentPeriods);
        $this->assertNull($employee->fresh()->separation_date);
    }

    #[Test]
    public function somebody_who_cannot_edit_employees_is_not_offered_it(): void
    {
        $employee = $this->hazel();
        $employee->update(['separation_date' => '2026-10-30', 'separation_type' => 'terminated']);

        $plain = User::factory()->create();
        $plain->assignRole('Admin');

        Livewire::actingAs($plain)
            ->test('employees.show', ['employee' => $employee->fresh()])
            ->assertDontSee('Rehire')
            ->call('openRehireModal')
            ->assertStatus(403);
    }

    #[Test]
    public function existing_employees_were_given_the_history_the_record_already_implied(): void
    {
        // The backfill in the migration. Everybody already on the system has
        // one period from their hire date, closed if they had left.
        $this->assertSame(
            Employee::whereNotNull('hire_date')->count(),
            EmploymentPeriod::distinct('employee_id')->count('employee_id'),
        );
    }
}
