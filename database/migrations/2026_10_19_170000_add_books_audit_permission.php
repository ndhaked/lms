<?php

use App\Actions\ProvisionLibraryRoles;
use App\Models\Library;
use App\Support\LibraryRoles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Adds the stock-take permission to the default roles of every existing library (nothing is removed). */
return new class extends Migration
{
    public function up(): void
    {
        ProvisionLibraryRoles::syncPermissions();

        foreach (Library::query()->get() as $library) {
            foreach (LibraryRoles::map() as $roleName => $permissions) {
                if (! in_array('books.audit', $permissions, true)) {
                    continue;
                }
                Role::where('library_id', $library->id)->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo('books.audit');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
