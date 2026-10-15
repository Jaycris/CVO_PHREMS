<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\BankDetailService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a decided bank change back.
 *
 * The Decided list had room for a bank name and a masked number, which is not
 * enough to answer what was approved. Worse, somebody who changed banks twice
 * appeared twice, and nothing on the page said which of the two the salary
 * actually goes to — so approving looked like it had added an account rather
 * than replaced one.
 */
class BankDetailHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected User $ceo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Notification::fake();

        $this->employee = Employee::factory()->create([
            'first_name' => 'George',
            'last_name' => 'Supangan',
        ]);
        $user = User::factory()->create();
        $this->employee->forceFill(['user_id' => $user->id])->save();
        $this->employee->refresh();

        $position = Position::factory()->create(['title' => 'Chief Executive Officer']);
        $position->givePermissionTo('bank_details.approve');

        $ceoEmployee = Employee::factory()->create(['position_id' => $position->id]);
        $this->ceo = User::factory()->create();
        $ceoEmployee->forceFill(['user_id' => $this->ceo->id])->save();
        $this->ceo->assignRole('Admin');
        $this->ceo = $this->ceo->fresh();

        $this->actingAs($this->ceo);
    }

    protected function service(): BankDetailService
    {
        return new BankDetailService();
    }

    /** Two approved changes, so the second has replaced the first. */
    protected function changedBanksTwice(): array
    {
        $service = $this->service();

        // The first account needs no approval — there is nothing to protect.
        $service->submit($this->employee, 'BPI', 'George Supangan', '1111111111');

        $first = $service->submit($this->employee->fresh(), 'Metrobank', 'George Supangan', '2222222222', 'Moved banks');
        $service->decide($first, $this->ceo, true);

        $second = $service->submit($this->employee->fresh(), 'Metrobank', 'George Supangan', '3333333333', 'Wrong account number');
        $service->decide($second, $this->ceo, true);

        return [$first->fresh(), $second->fresh()];
    }

    #[Test]
    public function only_the_latest_approved_account_is_marked_as_the_one_in_use(): void
    {
        [$superseded, $current] = $this->changedBanksTwice();

        $this->assertSame('3333333333', $this->employee->fresh()->bank_account_number);
        $this->assertTrue($current->isInForce());
        $this->assertFalse($superseded->isInForce(), 'the earlier account was replaced, not kept alongside');
    }

    #[Test]
    public function the_list_says_which_row_the_salary_goes_to(): void
    {
        $this->changedBanksTwice();

        Livewire::test('bank-details.index')
            ->assertSee('Salary goes here now');
    }

    #[Test]
    public function a_decided_row_opens_with_what_it_replaced_and_why(): void
    {
        [, $current] = $this->changedBanksTwice();

        Livewire::test('bank-details.index')
            ->call('openHistory', $current->id)
            ->assertSet('viewingId', $current->id)
            ->assertSee('Wrong account number')
            ->assertSee('paid to today')
            // What it replaced, which the list never showed at all.
            ->assertSee('Was paid to')
            ->assertSee('Metrobank');
    }

    #[Test]
    public function a_superseded_row_says_the_salary_no_longer_goes_there(): void
    {
        [$superseded] = $this->changedBanksTwice();

        Livewire::test('bank-details.index')
            ->call('openHistory', $superseded->id)
            ->assertSee('replaced by a later change');
    }

    #[Test]
    public function a_request_still_waiting_cannot_be_opened_as_history(): void
    {
        $this->service()->submit($this->employee, 'BPI', 'George Supangan', '1111111111');
        $pending = $this->service()->submit($this->employee->fresh(), 'GCash', 'George Supangan', '9999999999', 'Switching');

        // It belongs in the approval panel, where the full number is shown and
        // a decision can be made, not in the read-only history one.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test('bank-details.index')->call('openHistory', $pending->id);
    }

    #[Test]
    public function the_number_is_masked_until_asked_for_and_then_shows(): void
    {
        [, $current] = $this->changedBanksTwice();

        Livewire::test('bank-details.index')
            ->call('openHistory', $current->id)
            // Not in the markup at all until the button is pressed, so a screen
            // left open on this panel is not showing somebody's account.
            ->assertDontSee('3333333333')
            ->call('toggleNumber')
            ->assertSee('3333333333')
            ->call('toggleNumber')
            ->assertDontSee('3333333333');
    }

    #[Test]
    public function opening_another_row_starts_masked_again(): void
    {
        [$superseded, $current] = $this->changedBanksTwice();

        Livewire::test('bank-details.index')
            ->call('openHistory', $current->id)
            ->call('toggleNumber')
            ->assertSee('3333333333')
            // A number left showing must not greet whoever opens the next row.
            ->call('openHistory', $superseded->id)
            ->assertSet('revealNumber', false)
            ->assertDontSee('2222222222');
    }
}
