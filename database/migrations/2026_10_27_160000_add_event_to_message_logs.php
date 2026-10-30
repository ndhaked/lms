<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->string('event', 30)->nullable();      // what triggered it: joined, renewed, payment, expiring, due, test
            $table->string('ref', 80)->nullable();        // what it was about, so the same thing is not sent twice
            $table->index(['library_id', 'event', 'ref', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['library_id', 'event', 'ref', 'channel']);
            $table->dropColumn(['event', 'ref']);
        });
    }
};
