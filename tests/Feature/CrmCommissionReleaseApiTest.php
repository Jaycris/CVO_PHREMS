<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmCommissionReleaseApiTest extends TestCase
{
    use RefreshDatabase;
    private Employee $employee;
    private ?CommissionRun $run = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.crm.commission_release_token' => 'commission-lookup-test', 'services.crm.inbound_token' => 'directory-only-token']);
        $this->employee = Employee::factory()->create(['employee_id' => 'EMP-LOOKUP']);
    }

    private function item(array $extra = []): array
    {
        return array_replace(['key' => '35:100', 'crm_payment_id' => 100, 'crm_agent_id' => 35, 'sold_date' => '2026-10-14',
            'brand' => 'Inkspire Media House', 'author' => 'Test Author', 'book_title' => 'Test Book',
            'service' => 'Test Service', 'payment_method' => 'Card', 'sale_amount' => 2000], $extra);
    }

    private function sentSlip(?string $sent = '2026-10-30 12:00:00', ?Employee $employee = null): CommissionSlip
    {
        /*
         * Held rather than looked up again. firstOrCreate compares
         * period_start as "2026-10-10" while the date cast stores
         * "2026-10-10 00:00:00", so a second call finds nothing and trips the
         * unique key on run type and period.
         */
        $this->run ??= CommissionRun::create([
            'run_type' => 'custom',
            'period_start' => '2026-10-10',
            'period_end' => '2026-10-24',
            'status' => 'sent',
        ]);

        $run = $this->run;
        return CommissionSlip::create(['commission_run_id' => $run->id, 'employee_id' => ($employee ?? $this->employee)->id,
            'agent_name' => 'Test Agent', 'notified_at' => $sent, 'exchange_rate' => 56, 'card_hold_percent' => 15, 'statement_supplied' => true]);
    }

    private function line(CommissionSlip $slip, array $extra = [])
    {
        return $slip->lines()->create(array_replace(['sort_order' => 0, 'sold_date' => '2026-10-14', 'brand' => 'Inkspire Media House',
            'client' => 'Test Author', 'book_title' => 'Test Book', 'service' => 'Test Service', 'payment_method' => 'Card',
            'sale_amount' => 2000, 'service_commission' => 150, 'markup_commission' => 500, 'usd_total' => 650,
            'php_total' => 36400, 'card_hold_amount' => 5460, 'net_commission' => 30940], $extra));
    }

    private function lookup(array $items)
    {
        return $this->withToken('commission-lookup-test')->postJson('/api/crm/commission-release-status', [
            'hris_employee_id' => 'EMP-LOOKUP', 'month' => '2026-10', 'items' => $items,
        ]);
    }

    public function test_lookup_requires_the_dedicated_commission_token(): void
    {
        $body = ['hris_employee_id' => 'EMP-LOOKUP', 'month' => '2026-10', 'items' => [$this->item()]];
        $this->postJson('/api/crm/commission-release-status', $body)->assertUnauthorized();
        $this->withToken('directory-only-token')->postJson('/api/crm/commission-release-status', $body)->assertUnauthorized();
        config(['services.crm.commission_release_token' => '']);
        $this->lookup([$this->item()])->assertUnauthorized();
    }

    public function test_sent_slip_uses_stable_crm_ids_and_returns_only_the_matching_commission(): void
    {
        $slip = $this->sentSlip();
        $line = $this->line($slip, ['crm_payment_id' => 100, 'crm_agent_id' => 35]);
        $this->lookup([$this->item()])->assertOk()->assertJsonPath('complete', true)
            ->assertJsonPath('release_basis', 'sent')->assertJsonPath('data.0.status', 'released')
            ->assertJsonPath('data.0.reference', 'slip:'.$slip->id.'/line:'.$line->id)
            ->assertJsonPath('data.0.breakdown.usd_total', 650)->assertJsonPath('data.0.breakdown.net_commission', 30940)
            ->assertJsonMissingPath('employee.basic_salary');
    }

    public function test_unsent_or_missing_slips_are_unreleased_and_other_employees_are_never_used(): void
    {
        $this->line($this->sentSlip(null), ['crm_payment_id' => 100, 'crm_agent_id' => 35]);
        $this->line($this->sentSlip('2026-10-30 12:00:00', Employee::factory()->create()), ['crm_payment_id' => 100, 'crm_agent_id' => 35]);
        $this->lookup([$this->item()])->assertOk()->assertJsonPath('data.0.status', 'unreleased');
    }

    public function test_unique_legacy_lines_are_recognized_but_duplicates_and_reused_matches_are_unknown(): void
    {
        $slip = $this->sentSlip();
        $this->line($slip);
        $this->lookup([$this->item()])->assertOk()->assertJsonPath('data.0.status', 'released');
        $this->lookup([$this->item(), $this->item(['key' => '35:101', 'crm_payment_id' => 101])])->assertOk()
            ->assertJsonPath('data.0.status', 'unknown')->assertJsonPath('data.1.status', 'unknown');
        $this->line($slip, ['sort_order' => 1]);
        $this->lookup([$this->item()])->assertOk()->assertJsonPath('data.0.status', 'unknown');
    }

    public function test_legacy_slip_with_no_unique_match_and_missing_breakdowns_do_not_become_unreleased(): void
    {
        $slip = $this->sentSlip();
        $line = $this->line($slip, ['client' => 'Different Author']);
        $this->lookup([$this->item()])->assertOk()->assertJsonPath('data.0.status', 'unknown');
        $line->update(['crm_payment_id' => 100, 'crm_agent_id' => 35, 'usd_total' => null]);
        $this->lookup([$this->item()])->assertOk()->assertJsonPath('data.0.status', 'unknown');
    }

    public function test_newly_computed_slips_preserve_crm_transaction_ids_in_the_parser(): void
    {
        $transaction = \App\Services\Crm\CommissionTransaction::fromCrm(['crm_payment_id' => 123, 'crm_agent_id' => 35, 'sold_date' => '2026-10-14']);
        $this->assertSame(123, $transaction->crmPaymentId);
        $this->assertSame(35, $transaction->crmAgentId);
        $legacy = \App\Services\Crm\CommissionTransaction::fromCrm(['sold_date' => '2026-10-14']);
        $this->assertNull($legacy->crmPaymentId);
    }
}
