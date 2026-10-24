<?php

use App\Actions\ProvisionLibraryRoles;
use App\Models\Library;
use App\Support\LibraryRoles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The earlier migration that added the book permissions looked roles up by name only, so just the first library's
 * roles were updated. This gives every library's default roles the permissions they should have (nothing is removed).
 */
return new class extends Migration
{
    public function up(): void
    {
        ProvisionLibraryRoles::syncPermissions();

        $new = LibraryRoles::bookPermissions();

        foreach (Library::query()->get() as $library) {
            foreach (LibraryRoles::map() as $roleName => $permissions) {
                $add = array_values(array_intersect($permissions, $new));
                $role = Role::where('library_id', $library->id)->where('name', $roleName)->where('guard_name', 'web')->first();

                if ($add && $role) {
                    $role->givePermissionTo($add);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
