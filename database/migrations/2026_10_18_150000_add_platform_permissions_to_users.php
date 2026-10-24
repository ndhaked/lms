<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'platform_permissions')) Schema::table('users', function (Blueprint $table) {
            // NULL = unrestricted platform admin (the original super admin); a list = only those permissions.
            $table->json('platform_permissions')->nullable()->after('is_super_admin');
        });

        // The libraries' Owner roles gain the billing permissions added in this release.
        $ownerRoles = DB::table('roles')->where('name', 'Library Owner')->get(['id']);
        $perm = DB::table('permissions')->where('name', 'billing.manage')->first()
            ?? tap((object) ['id' => DB::table('permissions')->insertGetId(['name' => 'billing.manage', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()])]);

        foreach ($ownerRoles as $role) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $perm->id, 'role_id' => $role->id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('platform_permissions'));
    }
};
