<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phones that receive push notifications. One row per app install; signing out of the app removes it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255)->unique();              // Expo push token or Firebase (FCM) registration token
            $table->string('provider', 10);                      // expo | fcm
            $table->string('platform', 10);                      // android | ios
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();   // the sign-in it belongs to
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
