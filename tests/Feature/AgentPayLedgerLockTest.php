<?php

namespace Tests\Feature;

use App\Models\AgentPayment;
use App\Models\CashEntry;
use App\Models\Employee;
use App\Models\User;
use App\Services\Payroll\AgentPayService;
use Database\Seeders\CashCategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money In & Out cannot change an entry that came from Agent Pay.
 *
 * The payment is the source of truth. If the ledger could edit or delete its
 * entry, the two records of the same money would disagree and nobody could
 * tell which was right.
 */
class AgentPayLedgerLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(CashCategorySeeder::class);

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    protected function agentPayEntry(): CashEntry
    {
        Notification::fake();

        $agent = Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        app(AgentPayService::class)->record([
            'employee_id' => $agent->id,
            'month' => '2026-09',
            'description' => 'Basic salary — sales target reached',
            'amount' => '20000',
            'paid_on' => '2026-09-30',
        ], $this->admin);

        return CashEntry::sole();
    }

    #[Test]
    public function the_ledger_will_not_open_an_agent_pay_entry_for_editing(): void
    {
        $entry = $this->agentPayEntry();

        Livewire::test('cash.index')
            ->call('edit', $entry->id)
            ->assertSet('editingId', null)
            ->assertSee('written by Agent Pay');
    }

    #[Test]
    public function the_ledger_will_not_delete_an_agent_pay_entry(): void
    {
        $entry = $this->agentPayEntry();

        Livewire::test('cash.index')
            ->call('delete', $entry->id)
            ->assertSee('written by Agent Pay');

        $this->assertSame(1, CashEntry::count());
        $this->assertSame($entry->id, AgentPayment::sole()->cash_entry_id);
    }

    #[Test]
    public function entries_typed_in_by_hand_are_still_editable_and_deletable(): void
    {
        $handEntry = CashEntry::create([
            'entry_date' => '2026-09-10',
            'direction' => CashEntry::OUT,
            'amount' => 500,
            'description' => 'Office supplies',
            'recorded_by_user_id' => $this->admin->id,
        ]);

        Livewire::test('cash.index')
            ->call('edit', $handEntry->id)
            ->assertSet('editingId', $handEntry->id);

        Livewire::test('cash.index')->call('delete', $handEntry->id);

        $this->assertSame(0, CashEntry::count());
    }
}
