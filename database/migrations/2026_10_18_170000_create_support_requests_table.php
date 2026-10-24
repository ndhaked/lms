<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 150);
            $table->text('message');
            $table->string('status', 20)->default('open');   // open, resolved
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['library_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
