<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How many rows every table shows, and who may decide it.
 *
 * One number for the whole company, so it is not something an administrator
 * changes on somebody's behalf on a Tuesday. Kept apart from
 * app.settings.manage for the same reason bank_details.approve and
 * leave.opening_balance.manage are: opening the settings screen and setting
 * company-wide policy are different jobs.
 */
class TablePageSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AppSettingSeeder::class);
        AppSetting::flushCache();

        // Spatie caches the permission list, and the seeder has just added to
        // it — without this, granting a brand new permission fails with "there
        // is no permission named ...".
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        AppSetting::put('rows_per_page', '10', 'Rows per page');
        AppSetting::flushCache();
    }

    protected function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    #[Test]
    public function the_ceo_can_change_it(): void
    {
        $ceo = $this->userWith('app.settings.manage', 'app.settings.pagination.manage');

        Livewire::actingAs($ceo)
            ->test('settings')
            ->assertViewHas('canSetPagination', true)
            ->set('settings.rows_per_page', '50')
            ->call('save')
            ->assertHasNoErrors();

        AppSetting::flushCache();

        $this->assertSame(50, AppSetting::rowsPerPage());
    }

    #[Test]
    public function an_administrator_without_it_is_shown_the_size_but_not_offered_it(): void
    {
        // Knowing the size is useful; changing it for everybody is not theirs.
        $admin = $this->userWith('app.settings.manage');

        Livewire::actingAs($admin)
            ->test('settings')
            ->assertViewHas('canSetPagination', false)
            ->assertSee('Only the CEO or COO can change this')
            ->assertDontSee('wire:model="settings.rows_per_page"', false);
    }

    #[Test]
    public function an_administrator_cannot_change_it_by_crafting_the_request(): void
    {
        /*
         * The control is not rendered, but the save loop still receives every
         * key the component holds. Without the guard, anybody who can open
         * this screen could repaginate every table in the company.
         */
        $admin = $this->userWith('app.settings.manage');

        Livewire::actingAs($admin)
            ->test('settings')
            ->set('settings.rows_per_page', '100')
            ->call('save')
            ->assertHasNoErrors();

        AppSetting::flushCache();

        $this->assertSame(10, AppSetting::rowsPerPage());
    }

    #[Test]
    public function the_other_settings_still_save_for_an_ordinary_administrator(): void
    {
        // Refusing one setting must not make the whole screen useless.
        $admin = $this->userWith('app.settings.manage');

        Livewire::actingAs($admin)
            ->test('settings')
            ->set('settings.sms_offsite_work', '1')
            ->call('save')
            ->assertHasNoErrors();

        AppSetting::flushCache();

        $this->assertTrue(AppSetting::flag('sms_offsite_work'));
        $this->assertSame(10, AppSetting::rowsPerPage());
    }

    #[Test]
    public function the_dtr_has_its_own_size(): void
    {
        /*
         * The DTR holds a row per employee per day, so a fortnight for fifty
         * staff is seven hundred rows where the employee directory is fifty.
         * Ten at a time is right for one table and useless for the other.
         */
        AppSetting::put(AppSetting::DTR_ROWS_PER_PAGE, '50', 'DTR rows');
        AppSetting::flushCache();

        $component = Livewire::actingAs($this->userWith('attendance.view_all'))->test('attendance.dtr');

        $this->assertSame(50, $component->instance()->perPage());
        $this->assertSame(10, AppSetting::rowsPerPage(), 'The other tables are untouched.');
    }

    #[Test]
    public function the_dtr_follows_the_company_size_until_its_own_is_set(): void
    {
        // Nothing changes on the day this ships.
        AppSetting::where('key', AppSetting::DTR_ROWS_PER_PAGE)->delete();
        AppSetting::flushCache();

        $component = Livewire::actingAs($this->userWith('attendance.view_all'))->test('attendance.dtr');

        $this->assertSame(10, $component->instance()->perPage());
    }

    #[Test]
    public function the_dtr_size_is_also_the_ceos_to_set(): void
    {
        $admin = $this->userWith('app.settings.manage');

        Livewire::actingAs($admin)
            ->test('settings')
            ->set('settings.' . AppSetting::DTR_ROWS_PER_PAGE, '100')
            ->call('save')
            ->assertHasNoErrors();

        AppSetting::flushCache();

        $this->assertSame(10, AppSetting::dtrRowsPerPage(), 'An administrator must not change it.');

        $ceo = $this->userWith('app.settings.manage', 'app.settings.pagination.manage');

        Livewire::actingAs($ceo)
            ->test('settings')
            ->set('settings.' . AppSetting::DTR_ROWS_PER_PAGE, '100')
            ->call('save')
            ->assertHasNoErrors();

        AppSetting::flushCache();

        $this->assertSame(100, AppSetting::dtrRowsPerPage());
    }
}
