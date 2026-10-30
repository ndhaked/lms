<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A fingerprint / face / card machine a library has installed. Looked up by its serial number (ZKTeco "push" protocol)
        // or by its access token (JSON endpoint), so it is not tenant-scoped itself; every punch it sends is.
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('serial', 80)->unique();
            $table->string('location', 120)->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // Every punch a device reports, kept whether or not it led to a check-in, so a missing attendance row can be explained.
        Schema::create('biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_device_id')->constrained()->cascadeOnDelete();
            $table->string('pin', 40);
            $table->dateTime('punched_at');
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result', 20); // checked_in, checked_out, ignored, rejected, unknown
            $table->string('message', 255)->nullable();
            $table->timestamps();

            // Devices re-send what they were not sure arrived; the same punch must never count twice.
            $table->unique(['biometric_device_id', 'pin', 'punched_at']);
            $table->index(['library_id', 'punched_at']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('biometric_id', 40)->nullable()->after('student_code'); // the number enrolled on the machine (or the card number)
            $table->unique(['library_id', 'biometric_id']);
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->string('source', 12)->default('manual')->after('seat_id'); // manual | biometric
            $table->unsignedBigInteger('biometric_device_id')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn(['source', 'biometric_device_id']));
        Schema::table('students', function (Blueprint $t) {
            $t->dropUnique(['library_id', 'biometric_id']);
            $t->dropColumn('biometric_id');
        });
        Schema::dropIfExists('biometric_punches');
        Schema::dropIfExists('biometric_devices');
    }
};
