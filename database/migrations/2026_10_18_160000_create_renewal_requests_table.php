<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->string('message', 500)->nullable();
            $table->string('status', 20)->default('pending');   // pending, fulfilled, dismissed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['library_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_requests');
    }
};
