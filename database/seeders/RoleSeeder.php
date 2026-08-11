<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Only two roles exist, and they are an access tier rather than a job:
     * whether an account may hold administrative permissions at all.
     *
     * The job — Human Resources, Accountant, Team Leader — lives on the
     * position, which is where the default permissions hang. Keeping the two
     * apart is what lets someone hold the HR position while their account is
     * still limited to employee self-service.
     */
    public function run(): void
    {
        foreach (['Admin', 'Employee'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        foreach (config('permissions.groups') as $permissions) {
            foreach (array_keys($permissions) as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            }
        }

        // Permissions dropped from the catalogue should not linger as grants.
        Permission::whereNotIn('name', $this->catalogueNames())->delete();

        // Roles that used to double as job titles. Anyone holding one was
        // administrative staff, so they move to the Admin tier and their job
        // is expressed by their position from here on.
        $retired = Role::whereNotIn('name', ['Admin', 'Employee'])->get();

        foreach ($retired as $role) {
            $role->users()->each(fn ($user) => $user->syncRoles(['Admin']));
            $role->delete();
        }
    }

    /** @return list<string> */
    protected function catalogueNames(): array
    {
        return collect(config('permissions.groups'))
            ->flatMap(fn (array $permissions) => array_keys($permissions))
            ->all();
    }
}
