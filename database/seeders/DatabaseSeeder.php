<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(AppSettingSeeder::class);
        $this->call(LeaveTypeSeeder::class);
        $this->call(HolidaySeeder::class);
        $this->call(CashCategorySeeder::class);

        // The government contribution tables. Seeded switched off, matching a
        // company that has not completed employer registration — but the rates
        // have to be present, or turning the deductions on later would compute
        // every one of them as zero with nothing to say why.
        $this->call(StatutorySeeder::class);

        /*
         * A demo administrator, and never in production.
         *
         * The address and password are both in this file, so seeding a live
         * install would put an account anybody can read the credentials for on
         * a system holding everyone's salary. On a real deployment the first
         * administrator is created deliberately, with a password somebody
         * chose — see php artisan phrems:create-admin.
         */
        if (app()->environment('production')) {
            $this->command?->warn('Skipped the demo administrator: this is production.');
            $this->command?->warn('Create the first account with: php artisan phrems:create-admin');

            return;
        }

        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@creativision.test',
        ]);
        $admin->assignRole('Admin');
    }
}
