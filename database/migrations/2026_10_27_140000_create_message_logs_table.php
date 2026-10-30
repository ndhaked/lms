<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);                    // sms | whatsapp
            $table->string('mobile', 30);
            $table->unsignedSmallInteger('count')->default(1); // credits this message used
            $table->text('message');
            $table->string('status', 20)->default('sent');     // sent | failed
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['library_id', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};
