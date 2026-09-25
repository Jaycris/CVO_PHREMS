<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use App\Support\MaskedName;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A commission slip names the company's clients, and slips are printed,
 * emailed and left on desks. The agent still needs to recognise their own
 * sales, so the first name stays and the rest is starred out.
 */
class CommissionClientPrivacyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_surname_is_starred_but_the_first_name_stays(): void
    {
        $this->assertSame('Kyle ******a', MaskedName::of('Kyle Padilla'));
        $this->assertSame('Maria *****s', MaskedName::of('Maria Santos'));
    }

    #[Test]
    public function a_name_of_one_word_is_hidden_outright(): void
    {
        // Usually the surname, so keeping it would defeat the point.
        $this->assertSame('*****r', MaskedName::of('Potter'));
    }

    #[Test]
    public function every_word_after_the_first_is_hidden(): void
    {
        $this->assertSame('Juan ***a ***z', MaskedName::of('Juan Dela Cruz'));
    }

    #[Test]
    public function punctuation_and_length_survive_so_the_row_stays_recognisable(): void
    {
        // The hyphen and the full stop stay; a lone initial is already no name.
        $this->assertSame('Anne *****-****h', MaskedName::of('Anne Marie-Smith'));
        $this->assertSame('Kyle P. ******a', MaskedName::of('Kyle P. Padilla'));
    }

    #[Test]
    public function nothing_is_invented_for_a_blank_client(): void
    {
        $this->assertNull(MaskedName::of(null));
        $this->assertNull(MaskedName::of('   '));
    }

    /** A finalized slip with one sale on it. */
    protected function slip(): CommissionSlip
    {
        $this->seed(RoleSeeder::class);

        $employee = Employee::factory()->create(['commission_frequency' => 'monthly']);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'label' => 'August 2026',
            'status' => 'finalized',
            'finalized_at' => now(),
            'agent_count' => 1,
        ]);

        $slip = CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => $employee->id,
            'agent_name' => $employee->fullName(),
            'net_commission' => 5000,
            'statement_supplied' => true,
            // Only a slip that has been sent can be opened or downloaded.
            'notified_at' => now(),
        ]);

        $slip->lines()->create([
            'sort_order' => 0,
            'sold_date' => '2026-08-22',
            'brand' => 'Inkspire M.',
            'client' => 'Kyle Padilla',
            'book_title' => 'The man with t.',
            'service' => 'Monochrom.',
            'payment_method' => 'Card',
            'sale_amount' => 1299,
            'net_commission' => 9274.86,
        ]);

        return $slip->fresh(['lines', 'employee.user']);
    }

    #[Test]
    public function the_agents_own_slip_shows_the_masked_name(): void
    {
        $slip = $this->slip();

        Livewire::actingAs($slip->employee->user)
            ->test('my-commission')
            ->call('open', $slip->id)
            ->assertSee('Kyle ******a')
            ->assertDontSee('Kyle Padilla');
    }

    #[Test]
    public function the_downloaded_slip_carries_the_masked_name(): void
    {
        $slip = $this->slip();

        $pdf = $this->actingAs($slip->employee->user)
            ->get(route('my-commission.download', $slip))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kyle ******a', $pdf);
        $this->assertStringNotContainsString('Kyle Padilla', $pdf);
    }
}
