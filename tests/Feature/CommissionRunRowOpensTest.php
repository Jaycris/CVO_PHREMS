<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Clicking a row in the commission run directory opens the run.
 *
 * It used to be an Alpine handler on the row. When Alpine had not taken over
 * that table the click did nothing at all, and since the row is the only way
 * in, the run was unreachable — with no error to explain it.
 */
class CommissionRunRowOpensTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function clicking_a_run_opens_it(): void
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ceo = User::factory()->create();
        $ceo->assignRole('Admin');
        $ceo->givePermissionTo('commissions.runs.manage');

        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'label' => 'September 2026',
            'status' => 'computed',
            'agent_count' => 16,
        ]);

        Livewire::actingAs($ceo)
            ->test('commissions.runs')
            ->call('open', $run->id)
            ->assertRedirect(route('commissions.run-show', $run));
    }
}
