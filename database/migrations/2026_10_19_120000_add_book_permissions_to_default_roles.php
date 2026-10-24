<?php

use App\Actions\ProvisionLibraryRoles;
use App\Models\Library;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * New permissions for Books, E-books and circulation. Existing libraries get them added to their default roles
 * (nothing is removed, so roles the owner has customised keep their other permissions).
 */
return new class extends Migration
{
    public function up(): void
    {
        ProvisionLibraryRoles::syncPermissions();

        $new = LibraryRoles::bookPermissions();

        foreach (Library::query()->get() as $library) {
            Tenancy::run($library, function () use ($new, $library) {
                foreach (LibraryRoles::map() as $roleName => $permissions) {
                    $add = array_values(array_intersect($permissions, $new));
                    if ($add && ($role = Role::where('library_id', $library->id)->where('name', $roleName)->where('guard_name', 'web')->first())) {
                        $role->givePermissionTo($add);
                    }
                }
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
