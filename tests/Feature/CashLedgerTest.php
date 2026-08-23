<?php

namespace Tests\Feature;

use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\User;
use App\Services\CashLedgerService;
use Database\Seeders\CashCategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The money record.
 *
 * Everything here is arithmetic somebody will act on, so the totals are
 * asserted to the centavo rather than "roughly". The month boundary tests
 * matter most: an entry landing in the wrong month makes two months wrong at
 * once, and the error hides because both months still look plausible.
 */
class CashLedgerTest extends TestCase
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

    protected function ledger(): CashLedgerService
    {
        return new CashLedgerService();
    }

    protected function entry(string $date, string $direction, float $amount, ?string $category = null): CashEntry
    {
        return CashEntry::create([
            'entry_date' => $date,
            'direction' => $direction,
            'amount' => $amount,
            'description' => 'Test entry',
            'cash_category_id' => $category
                ? CashCategory::where('name', $category)->value('id')
                : null,
            'recorded_by_user_id' => $this->admin->id,
        ]);
    }

    protected function august(): array
    {
        return [Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')];
    }

    #[Test]
    public function it_totals_a_month_in_out_and_net(): void
    {
        $this->entry('2026-08-05', CashEntry::IN, 850000);
        $this->entry('2026-08-12', CashEntry::OUT, 287500.50);
        $this->entry('2026-08-20', CashEntry::OUT, 45000.25);

        $totals = $this->ledger()->totals(...$this->august());

        $this->assertSame(850000.00, $totals['in']);
        $this->assertSame(332500.75, $totals['out']);
        $this->assertSame(517499.25, $totals['net']);
        $this->assertSame(3, $totals['count']);
    }

    #[Test]
    public function a_month_with_nothing_in_it_totals_zero_rather_than_failing(): void
    {
        $totals = $this->ledger()->totals(...$this->august());

        $this->assertSame(0.0, $totals['in']);
        $this->assertSame(0.0, $totals['out']);
        $this->assertSame(0.0, $totals['net']);
        $this->assertSame(0, $totals['count']);
    }

    #[Test]
    public function net_goes_negative_when_more_went_out_than_came_in(): void
    {
        $this->entry('2026-08-05', CashEntry::IN, 100000);
        $this->entry('2026-08-06', CashEntry::OUT, 175000);

        $this->assertSame(-75000.00, $this->ledger()->totals(...$this->august())['net']);
    }

    #[Test]
    public function entries_on_the_first_and_last_day_of_the_month_are_counted(): void
    {
        // The classic off-by-one. Both of these belong to August, and a
        // boundary that excludes them makes July and September wrong too.
        $this->entry('2026-08-01', CashEntry::IN, 1000);
        $this->entry('2026-08-31', CashEntry::IN, 2000);
        $this->entry('2026-07-31', CashEntry::IN, 4000);
        $this->entry('2026-09-01', CashEntry::IN, 8000);

        $this->assertSame(3000.00, $this->ledger()->totals(...$this->august())['in']);
    }

    #[Test]
    public function it_groups_spending_by_category_biggest_first_with_shares(): void
    {
        $this->entry('2026-08-05', CashEntry::OUT, 300000, 'Salaries and Wages');
        $this->entry('2026-08-06', CashEntry::OUT, 90000, 'Rent');
        $this->entry('2026-08-07', CashEntry::OUT, 10000, 'Utilities');

        $rows = $this->ledger()->byCategory(...[...$this->august(), CashEntry::OUT]);

        $this->assertSame('Salaries and Wages', $rows[0]->name);
        $this->assertSame(300000.00, $rows[0]->total);
        $this->assertSame(75.0, $rows[0]->share);

        $this->assertSame('Rent', $rows[1]->name);
        $this->assertSame(22.5, $rows[1]->share);

        $this->assertSame('Utilities', $rows[2]->name);
        $this->assertSame(2.5, $rows[2]->share);
    }

    #[Test]
    public function money_that_moved_without_a_category_is_still_counted(): void
    {
        $this->entry('2026-08-05', CashEntry::OUT, 50000, 'Rent');
        $this->entry('2026-08-06', CashEntry::OUT, 50000);

        $rows = $this->ledger()->byCategory(...[...$this->august(), CashEntry::OUT]);

        // Dropping the uncategorised row would make the breakdown disagree
        // with the headline total, and the breakdown is the one people read.
        $this->assertSame(100000.00, (float) $rows->sum('total'));
        $this->assertTrue($rows->contains(fn ($r) => $r->name === 'Not categorised'));
    }

    #[Test]
    public function the_breakdown_keeps_the_two_sides_apart(): void
    {
        $this->entry('2026-08-05', CashEntry::IN, 500000, 'Client Payment');
        $this->entry('2026-08-06', CashEntry::OUT, 200000, 'Rent');

        $out = $this->ledger()->byCategory(...[...$this->august(), CashEntry::OUT]);
        $in = $this->ledger()->byCategory(...[...$this->august(), CashEntry::IN]);

        $this->assertCount(1, $out);
        $this->assertSame('Rent', $out[0]->name);
        $this->assertCount(1, $in);
        $this->assertSame('Client Payment', $in[0]->name);
    }

    #[Test]
    public function the_running_total_covers_every_month_at_once(): void
    {
        $this->entry('2026-06-10', CashEntry::IN, 100000);
        $this->entry('2026-07-10', CashEntry::OUT, 30000);
        $this->entry('2026-08-10', CashEntry::IN, 50000);

        $toDate = $this->ledger()->totalsToDate();

        $this->assertSame(150000.00, $toDate['in']);
        $this->assertSame(30000.00, $toDate['out']);
        $this->assertSame(120000.00, $toDate['net']);
    }

    #[Test]
    public function the_monthly_trend_only_lists_months_that_hold_entries(): void
    {
        $this->entry('2026-06-10', CashEntry::IN, 100000);
        $this->entry('2026-08-10', CashEntry::IN, 50000);

        $trend = $this->ledger()->monthlyTrend();

        // July is skipped rather than shown as a zero month. A company three
        // weeks into recording should not see a run of empty months implying
        // it earned nothing.
        $this->assertSame(['2026-06', '2026-08'], array_column($trend, 'month'));
        $this->assertSame('Jun 2026', $trend[0]['label']);
    }

    #[Test]
    public function amounts_are_stored_positive_with_the_direction_carrying_the_sign(): void
    {
        $in = $this->entry('2026-08-05', CashEntry::IN, 1000);
        $out = $this->entry('2026-08-06', CashEntry::OUT, 1000);

        $this->assertSame(1000.0, (float) $out->amount);
        $this->assertSame(1000.00, $in->signedAmount());
        $this->assertSame(-1000.00, $out->signedAmount());
    }

    #[Test]
    public function a_category_belongs_to_one_side_of_the_ledger(): void
    {
        // Rent is never money coming in. Offering it while recording a client
        // payment is how a ledger quietly stops adding up.
        $this->assertTrue(CashCategory::where('name', 'Rent')->where('direction', CashEntry::OUT)->exists());
        $this->assertFalse(CashCategory::where('name', 'Rent')->where('direction', CashEntry::IN)->exists());
        $this->assertTrue(CashCategory::where('name', 'Client Payment')->where('direction', CashEntry::IN)->exists());
    }

    #[Test]
    public function the_page_opens_and_shows_the_months_figures(): void
    {
        $this->entry(now()->toDateString(), CashEntry::OUT, 12345.67, 'Rent');

        $this->get('/money')
            ->assertOk()
            ->assertSee('Money In')
            ->assertSee('12,345.67');
    }

    #[Test]
    public function the_export_downloads_with_totals_on_the_sheet(): void
    {
        $this->entry('2026-08-05', CashEntry::IN, 850000, 'Client Payment');
        $this->entry('2026-08-12', CashEntry::OUT, 287500.50, 'Salaries and Wages');

        $csv = $this->get('/money/export?month=2026-08');
        $csv->assertOk();

        $body = $csv->streamedContent();

        $this->assertStringContainsString('Client Payment', $body);
        $this->assertStringContainsString('850000.00', $body);
        $this->assertStringContainsString('287500.50', $body);
        $this->assertStringContainsString('562499.50', $body, 'the net figure is missing from the export');
    }

    #[Test]
    public function a_mangled_month_in_the_url_falls_back_instead_of_erroring(): void
    {
        $this->get('/money/export?month=not-a-month')->assertOk();
        $this->get('/money/export')->assertOk();
    }

    #[Test]
    public function an_employee_cannot_see_the_companys_money(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $this->actingAs($employee)->get('/money')->assertForbidden();
        $this->actingAs($employee)->get('/money/export')->assertForbidden();
    }
}
