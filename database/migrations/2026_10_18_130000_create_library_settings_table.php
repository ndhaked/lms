<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->timestamps();
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->boolean('is_late')->default(false)->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn('is_late'));
        Schema::dropIfExists('library_settings');
    }
};
