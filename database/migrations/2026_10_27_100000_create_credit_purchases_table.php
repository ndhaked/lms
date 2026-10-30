<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->unsignedInteger('sms_credits')->default(0);
            $table->unsignedInteger('whatsapp_credits')->default(0);
        });

        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);                    // sms | whatsapp
            $table->unsignedInteger('credits');
            $table->decimal('rate', 8, 4);                    // price per credit at purchase time
            $table->decimal('amount', 10, 2);
            $table->string('method', 30);
            $table->string('reference', 100);
            $table->date('paid_on');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['library_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn(['sms_credits', 'whatsapp_credits']);
        });
    }
};
