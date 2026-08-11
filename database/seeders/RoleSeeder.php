<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Accountant exists before anyone holds it. Recipient lists are resolved
        // by role, so hiring one is a matter of assigning the role on the Users
        // page — no code change to start including them in notifications.
        foreach (['Admin', 'HR', 'Manager', 'Employee', 'CEO', 'Accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
