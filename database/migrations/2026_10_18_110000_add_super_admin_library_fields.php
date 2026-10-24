<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
        });

        Schema::table('libraries', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('slug'); // LIB-0001
            $table->foreignId('owner_id')->nullable()->after('code')->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
        });

        // Backfill codes for libraries created before this migration.
        foreach (DB::table('libraries')->whereNull('code')->orderBy('id')->get(['id']) as $row) {
            DB::table('libraries')->where('id', $row->id)->update(['code' => sprintf('LIB-%04d', $row->id)]);
        }

        // Existing libraries: treat active ones as already activated and link their first member as owner.
        DB::statement("UPDATE libraries SET activated_at = created_at WHERE status = 'active' AND activated_at IS NULL");
        DB::statement('UPDATE libraries l SET owner_id = (SELECT MIN(lu.user_id) FROM library_user lu WHERE lu.library_id = l.id) WHERE owner_id IS NULL');

        Schema::create('library_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['library_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_notes');
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['code', 'activated_at']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('phone'));
    }
};
