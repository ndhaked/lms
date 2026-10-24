<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('libraries', 'expiry_policy')) {
            Schema::table('libraries', function (Blueprint $table) {
                $table->string('expiry_policy', 20)->nullable();   // null = follow the platform default
            });
        }
    }

    public function down(): void
    {
        Schema::table('libraries', fn (Blueprint $table) => $table->dropColumn('expiry_policy'));
    }
};
