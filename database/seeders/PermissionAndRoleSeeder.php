<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;

class PermissionAndRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions
        $permissions = [
            'lookup_ebay_listing',
            'export_ebay_listing',
            'get_ebay_feedback',
            'export_ebay_feedback',
            'add_user',
            'list_users',
            'update_user',
            'delete_user',
            'add_role',
            'list_roles',
            'update_role',
            'delete_role',
            'settings',
            'update_settings'
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Forget cached permissions so that findByName can find them
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create Admin role and assign all permissions
        $adminRole = Role::findOrCreate('Admin', 'web');
        $adminRole->syncPermissions($permissions);

        // Assign Admin role to the first user if exists
        $user = User::first();
        if ($user) {
            $user->assignRole($adminRole);
        }
    }
}
