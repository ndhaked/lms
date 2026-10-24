<?php

namespace App\Actions;

use App\Models\Library;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ProvisionLibraryRoles
{
    public static function syncPermissions(): void
    {
        foreach (LibraryRoles::permissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    public function __invoke(Library $library): void
    {
        static::syncPermissions();

        Tenancy::run($library, function () {
            foreach (LibraryRoles::map() as $roleName => $permissions) {
                Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
