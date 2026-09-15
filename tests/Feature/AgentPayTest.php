<?php

namespace Tests\Feature;

use App\Models\AgentPayment;
use App\Models\CashEntry;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AgentPaySlipReady;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Recording pay for sales agents who are kept out of payroll runs.
 *
 * Before this, the money left the company with no record of who it went to.
 * A payment now writes a Money Out entry naming the agent and sends the agent a
 * pay slip, and the payment and its entry cannot drift apart.
 */
class AgentPayTest extends TestCase
{
    use RefreshDatabase;

    protected User $ceo;

    protected User $agentUser;

    protected Employee $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ceo = User::factory()->create();
        $this->ceo->assignRole('Admin');
        $this->ceo->givePermissionTo('payroll.agent_pay.manage');

        $this->agentUser = User::factory()->create(['is_active' => true]);
        $this->agentUser->assignRole('Employee');

        $this->agent = Employee::factory()->create([
            'employee_id' => 'EMP-9372',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);
        $this->agent->forceFill(['user_id' => $this->agentUser->id])->save();
    }

    /** @param array<string, mixed> $overrides */
    protected function record(array $overrides = [], ?Employee $agent = null)
    {
        $component = Livewire::actingAs($this->ceo)
            ->test('payroll.agent-pay')
            ->call('create')
            ->set('employeeId', ($agent ?? $this->agent)->id)
            ->set('month', '2026-09')
            ->set('description', 'Basic salary — sales target reached')
            ->set('amount', '20000')
            ->set('mtdUsd', '5200')
            ->set('paidOn', '2026-09-30')
            ->set('reference', 'BDO-778812');

        foreach ($overrides as $property => $value) {
            $component->set($property, $value);
        }

        return $component->call('save');
    }

    #[Test]
    public function recording_a_payment_writes_a_named_money_out_entry(): void
    {
        Notification::fake();

        $this->record()->assertHasNoErrors();

        $payment = AgentPayment::sole();
        $entry = CashEntry::sole();

        $this->assertSame($entry->id, $payment->cash_entry_id);
        $this->assertSame(CashEntry::OUT, $entry->direction);
        $this->assertSame('20000.00', $entry->amount);
        $this->assertSame('2026-09-30', $entry->entry_date->toDateString());
        $this->assertStringContainsString('Maria Santos', $entry->description);
        $this->assertStringContainsString('September 2026', $entry->description);
        $this->assertSame('Agent Pay', $entry->category->name);
        $this->assertSame('agent_payment', $entry->source_type);
        $this->assertSame($payment->id, (int) $entry->source_id);
    }

    #[Test]
    public function the_agent_is_sent_their_slip(): void
    {
        Notification::fake();

        $this->record()->assertSee('pay slip sent to Maria Santos');

        Notification::assertSentTo($this->agentUser, AgentPaySlipReady::class, function ($notification, $channels) {
            return $channels === ['mail', 'database'];
        });

        $this->assertNotNull(AgentPayment::sole()->notified_at);
    }

    #[Test]
    public function the_notification_carries_no_figures(): void
    {
        // Same rule as the payroll payslip email: amounts stay behind a login.
        Notification::fake();

        $this->record();

        $message = (new AgentPaySlipReady(AgentPayment::sole()))->toArray($this->agentUser)['message'];

        $this->assertStringNotContainsString('20,000', $message);
        $this->assertStringNotContainsString('20000', $message);
    }

    #[Test]
    public function an_agent_with_no_login_is_still_recorded_but_not_sent_anything(): void
    {
        Notification::fake();

        $noLogin = Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Cruz']);

        $this->record(agent: $noLogin)->assertSee('has no active PHREMS login');

        $this->assertSame(1, AgentPayment::count());
        $this->assertSame(1, CashEntry::count());
        $this->assertNull(AgentPayment::sole()->notified_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_agent_sees_their_slip_and_nobody_else_does(): void
    {
        Notification::fake();

        $this->record();

        $this->actingAs($this->agentUser)
            ->get('/my-payslips')
            ->assertOk()
            ->assertSee('Basic salary')
            ->assertSee('September 2026');

        $colleague = User::factory()->create();
        $colleague->assignRole('Employee');
        Employee::factory()->create()->forceFill(['user_id' => $colleague->id])->save();

        $this->actingAs($colleague)
            ->get('/my-payslips')
            ->assertOk()
            ->assertDontSee('Basic salary');
    }

    #[Test]
    public function the_agent_can_download_their_slip_and_nobody_else_can(): void
    {
        Notification::fake();

        $this->record();
        $payment = AgentPayment::sole();

        $this->actingAs($this->agentUser)
            ->get(route('my-payslips.agent-download', $payment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $colleague = User::factory()->create();
        $colleague->assignRole('Employee');
        Employee::factory()->create()->forceFill(['user_id' => $colleague->id])->save();

        $this->actingAs($colleague)
            ->get(route('my-payslips.agent-download', $payment))
            ->assertForbidden();
    }

    #[Test]
    public function correcting_a_payment_corrects_its_ledger_entry(): void
    {
        // Two records of the same money that disagree are worse than one.
        Notification::fake();

        $this->record();
        $payment = AgentPayment::sole();

        Livewire::actingAs($this->ceo)
            ->test('payroll.agent-pay')
            ->call('edit', $payment->id)
            ->set('amount', '15000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('15000.00', $payment->fresh()->amount);
        $this->assertSame('15000.00', CashEntry::sole()->amount);

        // A correction does not email the agent again on its own.
        Notification::assertSentToTimes($this->agentUser, AgentPaySlipReady::class, 1);
    }

    #[Test]
    public function deleting_a_payment_removes_its_ledger_entry(): void
    {
        Notification::fake();

        $this->record();
        $payment = AgentPayment::sole();

        Livewire::actingAs($this->ceo)
            ->test('payroll.agent-pay')
            ->call('prepareDelete', $payment->id)
            ->call('deleteConfirmed');

        $this->assertSame(0, AgentPayment::count());
        $this->assertSame(0, CashEntry::count());
    }

    #[Test]
    public function the_slip_can_be_sent_again(): void
    {
        Notification::fake();

        $this->record();

        Livewire::actingAs($this->ceo)
            ->test('payroll.agent-pay')
            ->call('resend', AgentPayment::sole()->id)
            ->assertSee('sent again');

        Notification::assertSentToTimes($this->agentUser, AgentPaySlipReady::class, 2);
    }

    #[Test]
    public function an_amount_is_required(): void
    {
        Notification::fake();

        $this->record(['amount' => ''])->assertHasErrors('amount');

        $this->assertSame(0, AgentPayment::count());
        $this->assertSame(0, CashEntry::count());
    }

    #[Test]
    public function only_the_ceo_or_coo_can_open_it(): void
    {
        // Somebody who manages the money ledger is still not somebody who
        // decides agents' pay.
        $accountant = User::factory()->create();
        $accountant->assignRole('Admin');
        $accountant->givePermissionTo('cash.manage');

        $this->actingAs($accountant)->get('/payroll/agent-pay')->assertForbidden();
        $this->actingAs($this->agentUser)->get('/payroll/agent-pay')->assertForbidden();
        $this->actingAs($this->ceo)->get('/payroll/agent-pay')->assertOk();
    }

    #[Test]
    public function the_agent_can_open_their_slip_like_a_regular_payslip(): void
    {
        Notification::fake();

        $this->record();
        $payment = AgentPayment::sole();

        Livewire::actingAs($this->agentUser)
            ->test('my-payslips')
            ->call('openAgentSlip', $payment->id)
            ->assertSet('openAgentSlipId', $payment->id)
            ->assertSee('Basic salary — sales target reached')
            ->assertSee('Net Pay')
            ->assertSee('20,000.00')
            ->assertSee('Nothing deducted.');
    }

    #[Test]
    public function nobody_can_open_somebody_elses_slip(): void
    {
        Notification::fake();

        $this->record();
        $payment = AgentPayment::sole();

        $colleague = User::factory()->create();
        $colleague->assignRole('Employee');
        Employee::factory()->create()->forceFill(['user_id' => $colleague->id])->save();

        Livewire::actingAs($colleague)
            ->test('my-payslips')
            ->call('openAgentSlip', $payment->id)
            ->assertForbidden();
    }
}
