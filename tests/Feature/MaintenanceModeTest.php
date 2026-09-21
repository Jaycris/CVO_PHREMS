<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\MaintenanceMode;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Switching PHREMS off for everyone but the CEO and COO.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected User $ceo;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings are held in a static between requests, and a test that
        // left maintenance on must not leak into the next one.
        AppSetting::flushCache();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ceo = User::factory()->create(['name' => 'Jay Cris']);
        $this->ceo->assignRole('Admin');
        $this->ceo->givePermissionTo(['app.settings.manage', MaintenanceMode::PERMISSION]);

        $this->staff = User::factory()->create();
        $this->staff->assignRole('Employee');
    }

    #[Test]
    public function staff_use_phrems_as_normal_while_it_is_off(): void
    {
        $this->actingAs($this->staff)->get('/announcements')->assertOk();
    }

    #[Test]
    public function staff_see_the_maintenance_page_while_it_is_on(): void
    {
        MaintenanceMode::turnOn($this->ceo);

        $this->actingAs($this->staff)
            ->get('/announcements')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '300')
            ->assertSee('temporarily unavailable');
    }

    #[Test]
    public function the_note_reaches_the_page(): void
    {
        MaintenanceMode::turnOn($this->ceo, 'Back by 3:00 PM.');

        $this->actingAs($this->staff)
            ->get('/announcements')
            ->assertViewHas('maintenanceMessage', 'Back by 3:00 PM.');
    }

    #[Test]
    public function the_ceo_keeps_working_and_is_reminded_it_is_on(): void
    {
        MaintenanceMode::turnOn($this->ceo);

        $this->actingAs($this->ceo)
            ->get('/settings')
            ->assertOk()
            ->assertSee('Maintenance is on. Everyone else sees the maintenance page.');
    }

    #[Test]
    public function the_login_page_stays_open(): void
    {
        // Or nobody allowed through could get back in to switch it off.
        MaintenanceMode::turnOn($this->ceo);

        $this->get('/login')->assertOk();
    }

    #[Test]
    public function the_crm_api_is_not_switched_off(): void
    {
        MaintenanceMode::turnOn($this->ceo);

        // Refused for having no token, not for maintenance.
        $status = $this->getJson(route('api.crm.health'))->status();

        $this->assertNotSame(503, $status);
    }

    #[Test]
    public function the_ceo_switches_it_on_and_off_from_system_settings(): void
    {
        Livewire::actingAs($this->ceo)
            ->test('settings')
            ->set('maintenanceNote', 'Back by 3:00 PM.')
            ->call('turnMaintenanceOn')
            ->assertSee('Maintenance is on.');

        $this->assertTrue(MaintenanceMode::isOn());
        $this->assertSame('Back by 3:00 PM.', MaintenanceMode::message());
        $this->assertSame('Jay Cris', MaintenanceMode::startedBy());

        Livewire::actingAs($this->ceo)
            ->test('settings')
            ->call('turnMaintenanceOff');

        $this->assertFalse(MaintenanceMode::isOn());
    }

    #[Test]
    public function settings_access_alone_cannot_switch_it_on(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('app.settings.manage');

        Livewire::actingAs($admin)
            ->test('settings')
            ->assertDontSee('Switch PHREMS off for everyone else')
            ->call('turnMaintenanceOn')
            ->assertForbidden();

        // Nor by slipping the key into the general Save.
        Livewire::actingAs($admin)
            ->test('settings')
            ->set('settings.' . MaintenanceMode::ENABLED, '1')
            ->call('save');

        $this->assertFalse(MaintenanceMode::isOn());
    }

    #[Test]
    public function only_the_ceo_can_preview_the_page(): void
    {
        $this->actingAs($this->ceo)
            ->get(route('maintenance.preview'))
            ->assertOk()
            ->assertSee('temporarily unavailable');

        $this->actingAs($this->staff)
            ->get(route('maintenance.preview'))
            ->assertForbidden();
    }
}
