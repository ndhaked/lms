<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'is_complimentary')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->boolean('is_complimentary')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('is_complimentary'));
    }
};
