<?php

namespace Tests\Feature\Payroll;

use App\Models\CashEntry;
use App\Models\FinalPay;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\FinalPayService;
use App\Services\Payroll\PayrollLedger;
use App\Services\Payroll\PayrollService;
use Database\Seeders\CashCategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;

/**
 * Salaries showing up in Money In & Out.
 *
 * The ledger used to show rent and electricity while a six-figure payroll left
 * the bank unrecorded, so the month's outgoings were never the company's real
 * outgoings. Written when the money moves — a run marked paid, a settlement
 * released — not when it is computed, because computed figures still change.
 */
class PayrollMoneyOutTest extends PayrollTestCase
{
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(CashCategorySeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->hr = User::factory()->create(['is_super_admin' => true]);
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo([
            'payroll.runs.manage', 'payroll.runs.finalize', 'payroll.final_pay.manage',
        ]);
        $this->actingAs($this->hr);
    }

    /** A finalized run for one employee on 30,000. */
    protected function finalizedRun(): PayrollRun
    {
        $employee = $this->makeEmployee(30000);
        $period = $this->period();
        $this->fillAttendance($employee, $period);

        $service = app(PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $this->hr);

        return $service->finalize($run->fresh(), $this->hr);
    }

    #[Test]
    public function a_run_marked_paid_becomes_one_money_out_entry(): void
    {
        $run = $this->finalizedRun();

        app(PayrollService::class)->markPaid($run, $this->hr);

        $entry = CashEntry::sole();

        $this->assertSame(CashEntry::OUT, $entry->direction);
        $this->assertSame(round((float) $run->total_net, 2), round((float) $entry->amount, 2));
        $this->assertSame($run->pay_date->toDateString(), $entry->entry_date->toDateString());
        $this->assertSame(PayrollLedger::RUN_SOURCE, $entry->source_type);
        $this->assertSame($run->id, $entry->source_id);
        $this->assertSame('Payroll', $entry->category->name);
        $this->assertStringContainsString('Payroll —', $entry->description);
    }

    #[Test]
    public function nothing_is_recorded_until_the_money_actually_goes_out(): void
    {
        $this->finalizedRun();

        // Computed and finalized are both still paper. Recording here would put
        // a figure in the ledger that a reopen could change underneath it.
        $this->assertSame(0, CashEntry::count());
    }

    #[Test]
    public function recording_the_same_run_twice_leaves_one_entry(): void
    {
        $run = app(PayrollService::class)->markPaid($this->finalizedRun(), $this->hr);

        (new PayrollLedger)->recordRun($run->fresh());

        $this->assertSame(1, CashEntry::count());
    }

    #[Test]
    public function a_released_settlement_becomes_one_money_out_entry(): void
    {
        Mail::fake();

        $employee = $this->makeEmployee(20000, 'day', [
            'first_name' => 'Rio',
            'last_name' => 'Moreno',
            'personal_email' => 'rio.moreno@gmail.com',
            'hire_date' => '2024-01-05',
        ]);
        $employee->update(['separation_date' => '2026-10-16']);

        $service = app(FinalPayService::class);
        $final = $service->clear($service->record($employee->fresh(), $this->hr), $this->hr);
        $service->emailStatement($final);
        $final = $service->release($final->fresh(), '2026-11-30');

        $entry = CashEntry::sole();

        $this->assertSame(CashEntry::OUT, $entry->direction);
        $this->assertSame(round((float) $final->net_amount, 2), round((float) $entry->amount, 2));
        $this->assertSame('2026-11-30', $entry->entry_date->toDateString());
        $this->assertSame(PayrollLedger::FINAL_PAY_SOURCE, $entry->source_type);
        $this->assertSame($final->id, $entry->source_id);
        $this->assertSame('Final Pay', $entry->category->name);
        $this->assertStringContainsString('Rio Moreno', $entry->description);
    }

    #[Test]
    public function a_settlement_still_held_is_not_in_the_ledger(): void
    {
        $employee = $this->makeEmployee(20000);
        $employee->update(['separation_date' => '2026-10-16']);

        app(FinalPayService::class)->record($employee->fresh(), $this->hr);

        $this->assertSame(FinalPay::HELD, FinalPay::sole()->status);
        $this->assertSame(0, CashEntry::count());
    }

    #[Test]
    public function money_in_and_out_will_not_change_a_payroll_entry(): void
    {
        app(PayrollService::class)->markPaid($this->finalizedRun(), $this->hr);
        $entry = CashEntry::sole();

        Livewire::test('cash.index')
            ->call('edit', $entry->id)
            ->assertSet('editingId', null)
            ->assertSee('written by Run Payroll');

        Livewire::test('cash.index')
            ->call('delete', $entry->id)
            ->assertSee('written by Run Payroll');

        $this->assertSame(1, CashEntry::count());
    }
}
